# PHP runtime update

Use PHP 8.2.35, 8.3.36, 8.4.27 or 8.5.12 (or later patch releases on those branches). `.php-version` selects the tested 8.2.35 baseline; Composer excludes earlier vulnerable patches on each supported branch.

Changing these files does not update a server. Install the patched interpreter before running the application. No deployment or production data is included in this change.

This repository contains four PDF service fragments, not a complete Laravel application. Its host must provide the App contracts/models, TCPDF, the Google Static Map integration and Laravel services. The added Composer file declares only the interpreter policy; it is not a complete host dependency manifest. Syntax can be checked independently; PDF rendering and map-service integration must be exercised in the complete host before deployment.

Validation: PHP 8.2.35 syntax check, Composer manifest validation and a matrix rejecting the previous vulnerable patch on each supported branch. CI uses the same patched PHP baseline.
