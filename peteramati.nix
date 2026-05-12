{ lib, pkgs, config, ... }:
let
  cfg = config.services.peteramati;
  passwordDir = "/var/lib/peteramati";
  passwordFile = "${passwordDir}/dbpassword";
  
  phpPackage = pkgs.php.withExtensions ({ all, ... }: with all; [
    mysqli gd mbstring curl openssl zlib
  ]);

  # App code location
  appCode = ./.; 

in
{
  options.services.peteramati = {
    location = lib.mkOption {
      type = lib.types.path;
      default = "/opt/peteramati";
      description = "File system path to place peteramati at";
    };
    site = lib.mkOption {
      type = lib.types.str;
      default = "example.com";
      description = "URL of this instance";
    };
    shortName = lib.mkOption {
      type = lib.types.str;
      default = lib.strings.removePrefix "https://" cfg.site;
      description = "Short name of the class";
    };
    longName = lib.mkOption {
      type = lib.types.str;
      default = "";
      description = "Long name of the class";
    };
    contact = lib.mkOption {
      type = lib.types.submodule {
        options = {
          name = lib.mkOption {
            type = lib.types.str;
            default = "Peter Amati";
            description = "Name of instructor";
          };
          email = lib.mkOption {
            type = lib.types.str;
            default = "admin@" + (lib.strings.removePrefix "https://" cfg.site);
            description = "Contact email";
          };
        };
      };
      default = {};
    };
    db = lib.mkOption {
      type = lib.types.submodule {
        options = {
          name = lib.mkOption {
            type = lib.types.str;
            default = "peteramati";
            description = "Name of the database";
          };
          user = lib.mkOption {
            type = lib.types.str;
            default = "peteramati";
            description = "Database user";
          };
        };
      };
      default = {};
    };
    phpfpm = lib.mkOption { 
      type = lib.types.submodule {
        options = {
          port = lib.mkOption {
            type = lib.types.str;
            default = "9000";
            description = "Local port to listen on";
          };
          user = lib.mkOption {
            type = lib.types.str;
            default = "www-data";
            description = "User to execute php-fpm as";
          };
          group = lib.mkOption {
            type = lib.types.str;
            default = "www-data";
            description = "Group to execute php-fpm as";
          };
        };
      };
      default = {};
    };
  };

  config = {
    nixpkgs.hostPlatform = "x86_64-linux";

    systemd.services.gen-db-password = {
      description = "Generate random password for peteramati database";
      enable = true;
      wantedBy = [ "multi-user.target" ];
      serviceConfig = {
        Type = "oneshot";
        RemainAfterExit = true;
      };
      script = ''
        mkdir -p ${passwordDir}
        if [ ! -f ${passwordFile} ]; then
          tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32 > ${passwordFile}
          chmod 600 ${passwordFile}
        fi
        chown -R ${cfg.phpfpm.user}:${cfg.phpfpm.group} ${passwordDir}
      '';
    };

    systemd.services.mariadb = {
      description = "MariaDB database server";
      enable = true;
      wantedBy = [ "multi-user.target" ];
      after = [ "network.target" "gen-db-password.service" ];
      serviceConfig = {
        ExecStart = "${pkgs.mariadb}/bin/mariadbd-safe --datadir=/var/lib/mysql";
        User = "mysql"; # Needs to exist on host or be created
        Group = "mysql";
        RuntimeDirectory = "mysqld";
        PIDFile = "/run/mysqld/mysqld.pid";
      };
    };

    systemd.services.init-peteramati-db = {
      description = "Initialize peteramati database";
      enable = true;
      wantedBy = [ "multi-user.target" ];
      after = [ "mariadb.service" ];
      requires = [ "mariadb.service" ];
      serviceConfig = {
        Type = "oneshot";
        RemainAfterExit = true;
      };
      script = ''
        # Wait for MariaDB to be ready
        until ${pkgs.mariadb}/bin/mariadb-admin ping >/dev/null 2>&1; do
          sleep 1
        done

        PASS=$(cat ${passwordFile})
        
        # Create database and user if they don't exist
        ${pkgs.mariadb}/bin/mariadb -e "CREATE DATABASE IF NOT EXISTS \`${cfg.db.name}\`;"
        ${pkgs.mariadb}/bin/mariadb -e "CREATE USER IF NOT EXISTS '${cfg.db.user}'@'localhost' IDENTIFIED BY '$PASS';"
        ${pkgs.mariadb}/bin/mariadb -e "CREATE USER IF NOT EXISTS '${cfg.db.user}'@'127.0.0.1' IDENTIFIED BY '$PASS';"
        ${pkgs.mariadb}/bin/mariadb -e "GRANT ALL PRIVILEGES ON \`${cfg.db.name}\`.* TO '${cfg.db.user}'@'localhost';"
        ${pkgs.mariadb}/bin/mariadb -e "GRANT ALL PRIVILEGES ON \`${cfg.db.name}\`.* TO '${cfg.db.user}'@'127.0.0.1';"
        ${pkgs.mariadb}/bin/mariadb -e "FLUSH PRIVILEGES;"

        # Initialize schema if empty
        TABLE_COUNT=$(${pkgs.mariadb}/bin/mariadb -N -s -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${cfg.db.name}';")
        if [ "$TABLE_COUNT" -eq 0 ]; then
          ${pkgs.mariadb}/bin/mariadb "${cfg.db.name}" < ${./src/schema.sql}
        fi
      '';
    };

    # PHP-FPM service defined manually for system-manager
    systemd.services.phpfpm-peteramati = {
      description = "PHP-FPM pool for peteramati";
      enable = true;
      wantedBy = [ "multi-user.target" ];
      after = [ "network.target" "mariadb.service" ];
      serviceConfig = {
        ExecStart = "${phpPackage}/bin/php-fpm -y ${pkgs.writeText "phpfpm.conf" ''
          [global]
          pid = /run/phpfpm-peteramati.pid
          error_log = stderr
          daemonize = no

          [www]
          listen = 127.0.0.1:${cfg.phpfpm.port}
          user = ${cfg.phpfpm.user}
          group = ${cfg.phpfpm.group}
          pm = dynamic
          pm.max_children = 5
          pm.start_servers = 2
          pm.min_spare_servers = 1
          pm.max_spare_servers = 3
        ''}";
        Type = "simple";
        RuntimeDirectory = "phpfpm";
      };
    };

    environment.etc."peteramati/options.php" = {
      text = ''<?php
        global $Opt;
        $Opt["safePasswords"] = true;
        $Opt["passwordHmacKey"] = null;
        $Opt["sendEmail"] = true;
        $Opt["dbName"] = "${cfg.db.name}";
        $Opt["dbUser"] = "${cfg.db.user}";
        $Opt["dbPassword"] = trim(file_get_contents('${passwordFile}'));
        $Opt["shortName"] = "${cfg.shortName}";
        $Opt["longName"] = "${cfg.longName}";
        $Opt["paperSite"] = "${cfg.site}";
        $Opt["contactName"] = "${cfg.contact.name}";
        $Opt["contactEmail"] = "${cfg.contact.email}";
        $Opt["emailFrom"] = "${cfg.contact.email}";
      '';
      mode = "0640";
      user = cfg.phpfpm.user;
      group = cfg.phpfpm.group;
    };
  }; 
}
