{
  description = "Peteramati Grading Server";

  inputs = {
    # Pull stable packages from the official Nix channel
    nixpkgs.url = "github:nixos/nixpkgs/nixos-25.11";

    # System-Manager handles systemd services cleanly on Ubuntu
    system-manager = {
      url = "github:numtide/system-manager";
      inputs.nixpkgs.follows = "nixpkgs";
    };
  };

  outputs = { self, nixpkgs, system-manager, ... }: {
    # Define a system configuration named 'webserver'
    systemConfigs.default = system-manager.lib.makeSystemConfig {
      modules = [
        # 1. Provide global system-manager defaults
        {
          config = {
            nixpkgs.hostPlatform = "x86_64-linux"; # Adjust if your VM is ARM64
          };
        }
        # 2. Wire in your main webserver configuration file
        ./peteramati.nix
      ];
    };
  };
}
