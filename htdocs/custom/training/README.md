# Training 0.6.0 for Dolibarr

External module for Dolibarr 24.0.2, PHP 8.4 and MySQL/InnoDB.
Courses extend standard Dolibarr services; learners link to standard contacts.
Includes versioned programs, sessions, slots, named administrative enrollment,
capacity control with named temporary reservations, attendance, invoice-line allocations and transactional audit. Online checkout is planned.

## Installation

Download `module_training-0.6.0.zip` from the project `dist/` directory.
In Dolibarr, open Setup → Modules/Applications → Deploy/install external module,
upload this ZIP and activate Training. Dolibarr installs it in `htdocs/custom/training`.
Enable Services, Third Parties and Invoices. Grant course/session/contact read and the
specific write/enrollment/attendance/billing permissions required by each internal user.
Courses and sessions are tabs on standard service cards. Attendance is linked
from each teaching slot on the session card. Invoice allocation is linked from the
session card; read docs/fakturalinjefordeling.md in the repository for its scope.

For an upgrade, back up the database and module files, upload the new ZIP,
then deactivate/reactivate Training so missing tables and new rights are registered.
Deactivation retains course data and audit. No core files are modified.
Use a test instance before production. The module ID 504850 and permission IDs
504851–504868 are provisional private IDs; verify they do not conflict locally.
Full installation/UI verification on the target server remains to be performed.

Source and documentation: https://github.com/smoeberg/Dolibarr_tms

Attendance uses a local date/time picker in the session timezone, explicit
clock-change occurrence selection, saved-status feedback and readable audit history.

Trainer profiles link to standard internal Users. Assignments grant attendance
access only together with ownattendance/read, write and correct permissions.
Trainers should not receive the coordinator-wide attendance permissions.
See docs/underviseradgang.md in the repository for role setup and upgrade steps.

Coordinators can reserve named standard Contacts for 15 minutes from the session
card, approve a whole group or release it with a reason. Expired holds stop
consuming capacity without a job. Administrative approval does not indicate
payment; Website checkout and payment verification are not included.
See docs/pladsreservationer.md in the repository.
