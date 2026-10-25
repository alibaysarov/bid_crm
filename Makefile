.PHONY: postman postman-sync

postman:
	php openapi/generate-postman.php

postman-sync: postman
	php artisan postman:sync
