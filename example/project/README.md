# Demo root project

Demo root project integrating with Drupal libraries installer.

Update locally after committing by running `composer update zodiacmedia/drupal-libraries-installer`.

Or symlink to a copy of the current root project with:

```bash
# Enable "symlink-root-project" mode (--json requires composer 2).
composer config --json extra.symlink-root-project true
# Apply the symlink.
composer update --lock
```
