# Language Cleaner

The maintained cleaner now lives in patstools_pub, not patstools. In sugarutils,
use `lea` for the read-only assessment and `lec` for cleanup. Cleanup requires
typing `CLEAN` after seeing the instance root and configured URL. Use a clone
first and keep other repair/customization processes stopped during the run.

## Publishing

Commit the cleaner, sugarutils changes and tests to patstools_pub. Create and push
the tag `language-cleaner-20261123.150000` at that tested commit before distributing
the updated sugarutils. Its downloader uses that tag and an embedded SHA-256;
a missing tag, download failure or mismatched checksum prevents execution.
No release/tag has been published automatically.

For later releases, change the version in the cleaner and sugarutils, update the
embedded checksum (`shasum -a 256 sugarappliststringscleaner.php`), run tests, and
publish a new matching tag. Do not move an existing release tag.

## Standalone Use

On a shadowed instance, run the downloaded script using:

```bash
shadowy /absolute/path/sugarappliststringscleaner.php /absolute/instance/root
```

sugarutils uses a private launcher to preserve the explicit instance root even
when shadowy changes the working directory. It does not fall back to plain PHP
if shadowy is missing. On a regular installation, run with that installation's
PHP runtime and the same explicit root argument.

## Safety and Recovery

The tool verifies a ZIP of both source and compiled folders before rebuilding.
It rebuilds extensions before reading effective values, stages consolidated
files, and checks their values before replacing the source directory. It retains
the original source directory beside it with an `.original_...` suffix. It then
rebuilds extensions again and compares effective values for every language.
It uses extension rebuild only, not database repair, and does not change the
Health Check threshold. sugarutils reruns the assessment only on success.

A post-write failure explicitly reports incomplete rebuild/verification and the
backup paths. Do not continue an upgrade on that basis. Preserve the failed
output, restore the retained original source directory if needed, and rebuild
through Sugar's supported repair workflow. Do not unpack the original files on
top of consolidated files. Retained backups require intentional later cleanup.

Unexpected source files/directories/symlinks stop cleanup for manual review.
Language PHP executes in the Sugar environment; only use this on trusted instance
customizations. Fixtures test file safety and effective values, not the actual
host's shadowy wrapper or Sugar runtime. Verify on a clone before production.
