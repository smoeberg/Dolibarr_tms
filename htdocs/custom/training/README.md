# Training 0.3.0 for Dolibarr

External module for Dolibarr 24.0.2, PHP 8.4 and MySQL/InnoDB.
Courses extend standard Dolibarr services; learners link to standard contacts.
Includes versioned programs, sessions, slots, named administrative enrollment,
capacity control, attendance and transactional audit. Online checkout is planned.

## Installation

Download `module_training-0.3.0.zip` from the project `dist/` directory.
In Dolibarr, open Setup → Modules/Applications → Deploy/install external module,
upload this ZIP and activate Training. Dolibarr installs it in `htdocs/custom/training`.
Enable Services and Third Parties. Grant course/session/contact read and the
specific write/enrollment/attendance permissions required by each internal user.
Courses and sessions are tabs on standard service cards. Attendance is linked
from each teaching slot on the session card.

For an upgrade, back up the database and module files, upload the new ZIP,
then deactivate/reactivate Training so missing tables and new rights are registered.
Deactivation retains course data and audit. No core files are modified.
Use a test instance before production. The module ID 504850 and permission IDs
504851–504860 are provisional private IDs; verify they do not conflict locally.
Full installation/UI verification on the target server remains to be performed.

Source and documentation: https://github.com/smoeberg/Dolibarr_tms
