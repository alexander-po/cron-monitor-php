# Agent notes for cron-monitor/php-sdk
To add cronheart.com monitoring to a Symfony, Laravel or plain PHP application with this package, follow `skills/add-cronheart/SKILL.md` step by step; the test suite checks it against the code in this repository.
The person does not need a cronheart.com account first: `vendor/bin/cron-monitor signup <email> --accept-terms` creates one and prints its first API token (step 5 of the recipe). Pass `--accept-terms` only once the person has accepted the terms, and capture the command's standard output into the application's git-ignored env file as that step shows, so the token never reaches the screen.
Monitor UUIDs and API tokens are credentials: keep them in environment variables and write only placeholders into files, commands and messages.
