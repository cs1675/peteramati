<?php
// manual_run.php -- Manually run a repository command in the foreground
// Usage: php manual_run.php [options] PSET_ID STUDENT_ID RUNNER_NAME [COMMIT_HASH]

$pa_root = getenv("PETERAMATI_ROOT");
$args = [];
for ($i = 1; $i < $argc; ++$i) {
    if (($argv[$i] === "--root" || $argv[$i] === "-R") && isset($argv[$i + 1])) {
        $pa_root = $argv[++$i];
    } else {
        $args[] = $argv[$i];
    }
}

if (!$pa_root) {
    $pa_root = __DIR__;
}
if (!file_exists("{$pa_root}/src/init.php")) {
    fwrite(STDERR, "Error: Peteramati root '{$pa_root}' is invalid (src/init.php not found).\n");
    exit(1);
}

// Ensure SiteLoader finds the correct root
set_include_path(get_include_path() . PATH_SEPARATOR . "{$pa_root}/src");
require_once("{$pa_root}/src/init.php");
SiteLoader::$root = realpath($pa_root);

if (count($args) < 3) {
    fwrite(STDERR, "Usage: php manual_run.php [options] PSET_ID STUDENT_ID RUNNER_NAME [COMMIT_HASH]\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  -R, --root DIR    Path to Peteramati web app code (or use PETERAMATI_ROOT env)\n");
    fwrite(STDERR, "Example: php manual_run.php pset1 student1 test\n");
    exit(1);
}

$pset_id = $args[0];
$student_id = $args[1];
$runner_name = $args[2];
$commit_hash = (isset($args[3]) && $args[3] !== "") ? $args[3] : null;

$conf = Conf::$main;

// 1. Find the problem set
$pset = $conf->pset_by_key($pset_id);
if (!$pset) {
    fwrite(STDERR, "Error: Problem set '$pset_id' not found.\n");
    $psets = array_map(function ($p) { return $p->urlkey; }, $conf->pset_list());
    fwrite(STDERR, "Available psets: " . join(", ", $psets) . "\n");
    exit(1);
}

// 2. Find the runner
$runner = $pset->runners[$runner_name] ?? null;
if (!$runner) {
    fwrite(STDERR, "Error: Runner '$runner_name' not found in pset '$pset_id'.\n");
    fwrite(STDERR, "Available runners: " . join(", ", array_keys($pset->runners)) . "\n");
    exit(1);
}

// 3. Find the student
$student = $conf->user_by_whatever($student_id);
if (!$student) {
    fwrite(STDERR, "Error: Student '$student_id' not found.\n");
    exit(1);
}

// 4. Create a PsetView for this student and pset
// This handles finding the repository and the specific commit.
$viewer = $conf->site_contact(); // Use a site contact as viewer for higher permissions
$info = PsetView::make($pset, $student, $viewer, $commit_hash);

if (!$info->repo) {
    fwrite(STDERR, "Error: No repository configured for student '$student_id' and pset '$pset_id'.\n");
    exit(1);
}

// Ensure the repository is checked out locally
$info->repo->ensure_repodir();

if (!$info->commit()) {
    fwrite(STDERR, "Error: No commit found for student '$student_id' and pset '$pset_id'.\n");
    if ($commit_hash) {
        fwrite(STDERR, " (Requested commit: $commit_hash)\n");
    }
    exit(1);
}

// 5. Create a QueueItem to execute the run
$qi = QueueItem::make_info($info, $runner);
// Set flags for foreground execution with verbose output
$qi->flags |= QueueItem::FLAG_FOREGROUND | QueueItem::FLAG_FOREGROUND_VERBOSE | QueueItem::FLAG_NOEVENTSOURCE;

echo "Running '{$runner_name}' for '{$student->email}' on pset '{$pset_id}'...\n";
echo "Repository: " . $info->repo->url . "\n";
echo "Commit:     " . $info->commit()->hash . " (" . date("Y-m-d H:i:s", $info->commit()->commitat) . ")\n";
echo "--------------------------------------------------\n";

// 6. Execute!
// This will set up the jail and run the command.
$qi->step(new QueueState);

echo "--------------------------------------------------\n";
if ($qi->foreground_command_status !== null) {
    echo "Command finished with exit status: " . $qi->foreground_command_status . "\n";
    exit($qi->foreground_command_status);
} else if ($qi->last_error) {
    echo "Error: " . $qi->last_error . "\n";
    exit(1);
} else {
    echo "Command finished.\n";
    exit(0);
}
