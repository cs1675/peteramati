<?php
// local_repositorysite.php -- Peteramati local git fixture repositories
// (dev-only: enabled by adding "local" to $Opt["repositorySites"] and
// registering RepositorySite::$sitemap["local"] = "Local_RepositorySite"
// in conf/options.php; inert otherwise)
// See LICENSE for open-source distribution terms

class Local_RepositorySite extends RepositorySite {
    /** @var Conf */
    public $conf;
    /** @var string */
    public $base;
    /** @var string */
    public $localpath;

    function __construct($url, $base, $localpath, Conf $conf) {
        $this->url = $url;
        $this->base = $base;
        $this->localpath = $localpath;
        $this->conf = $conf;
        $this->siteclass = "local";
    }

    /** @return ?string realpath of the configured fixture root, or null */
    static private function root(Conf $conf) {
        $root = $conf->opt("localRepoRoot");
        if (!$root || !is_string($root)) {
            return null;
        }
        $rp = realpath($root);
        return $rp !== false ? $rp : null;
    }

    // Accepted form: "local:<owner>/<name>", owner/name limited to
    // [A-Za-z0-9_-]+ so the string can never contain "..", "/", a URL
    // scheme (no "ext::", "file://", etc.), or anything else that would
    // change git's or the filesystem's interpretation of it. $localpath is
    // always built by US from validated segments joined onto the
    // configured root -- never derived directly from unvalidated input.
    const RE = '/\Alocal:([A-Za-z0-9_-]+)\/([A-Za-z0-9_-]+)\z/';

    /** @param string $url
     * @return ?Local_RepositorySite */
    static function make_url($url, Conf $conf) {
        if (!preg_match(self::RE, $url, $m) || !($root = self::root($conf))) {
            return null;
        }
        $base = "{$m[1]}/{$m[2]}";
        $localpath = "{$root}/{$m[1]}/{$m[2]}.git";
        // Belt-and-suspenders containment check.
        if (strncmp($localpath, $root . "/", strlen($root) + 1) !== 0) {
            return null;
        }
        return new Local_RepositorySite("local:{$base}", $base, $localpath, $conf);
    }
    static function sniff_url($url) {
        return preg_match(self::RE, $url) ? 2 : 0;
    }
    static function home_link($html) {
        return $html;
    }
    static function echo_username_form(Contact $user, $first) {
        // No external identity needed for local fixtures.
    }

    function friendly_siteclass() {
        return "Local";
    }
    static function global_friendly_siteclass() {
        return "Local";
    }
    static function global_friendly_siteurl() {
        return "";
    }

    /** @return string */
    function https_url() {
        return $this->localpath;
    }
    /** @return string */
    function ssh_url() {
        return $this->localpath;
    }
    /** @return string */
    function git_url() {
        return $this->localpath;
    }
    /** @return string */
    function friendly_url() {
        return $this->url;
    }
    function owner_name() {
        if (preg_match('{\A([^/]+)/([^/]+)\z}', $this->base, $m)) {
            return [$m[1], $m[2]];
        }
        return false;
    }

    function message_defs(Contact $user) {
        return ["REPOURL" => $this->localpath, "REPOGITURL" => $this->localpath,
                 "REPOBASE" => $this->base, "GITHUB" => 0];
    }

    // Base RepositorySite::gitfetch() is a hardcoded no-op (returns
    // false); only GitHub_RepositorySite overrides it today. We need the
    // same "kick off batch/repofetch.php" behavior GitHub gets, but
    // unconditionally (no credential gate) since local fixtures need none.
    function gitfetch($repoid, $cacheid, $foreground) {
        $arg = $foreground ? [] : ["--bg"];
        $php = $this->conf->opt("phpCommand") ?? "php";
        $sp = Subprocess::run([
            $php, "batch/repofetch.php", "-r", $repoid, ...$arg
        ], SiteLoader::$root);
        if (!$sp->ok) {
            error_log("`php batch/repofetch.php -r {$repoid}` failed: {$sp->status}, {$sp->stderr}");
        }
        return true;
    }

    // Treat fixtures like real (private) student repos for display purposes.
    function validate_open() {
        return 0;
    }

    /** @return -1|0|1 */
    function validate_working(Contact $user, ?MessageSet $ms = null) {
        if (!is_dir($this->localpath) || !file_exists("{$this->localpath}/HEAD")) {
            if ($ms) {
                $ms->error_at("repo", "No local repository fixture at "
                    . htmlspecialchars($this->localpath) . ". Create one with "
                    . "`pa-seed-repo {$this->base}`.");
                $ms->error_at("working");
            }
            return 0;
        }
        return 1;
    }

    function validate_ownership_always() {
        return false;
    }
    /** @return -1|0|1 */
    function validate_ownership(Repository $repo, Contact $user, ?Contact $partner = null,
                                 ?MessageSet $ms = null) {
        // Dev fixtures: any local dev user may claim any fixture repo.
        return 1;
    }
}
