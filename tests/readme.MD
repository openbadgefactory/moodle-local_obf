# PHPUnit testing with Moodle Docker
This documentation describes how to:
1. install Moodle and Moodle Docker using version control  
2. install the OBF Moodle plugin using version control  
3. run PHPUnit tests for the OBF Moodle plugin
If you don't want to use Moodle Docker, Moodle's own documentation how to run PHPUnit tests can be found: 
https://moodledev.io/general/development/tools/phpunit 
If you're interested how to write PHPUnit tests, read Moodle's documentation about the subject: https://docs.moodle.org/dev/Writing_PHPUnit_tests 

## Project structure and important notes
Note: We'll set up the local development environment project structure followingly and rest of the instructions depend upon these paths.
Note: You'll need Linux environment to continue. Undersigned used WSL.  
 - ~/*moodle-plugin-dev*/ will be our root folder for the project
 - ~/moodle-plugin-dev/*moodle-docker* will be the location for Moodle Docker installation
 - ~/moodle-plugin-dev/*moodle* will be the location for Moodle installation
 - ~/moodle-plugin-dev/*moodle/local/obf* will be the location for OBF Moodle plugin installation
OBF PHPUnit tests will then be located in ~/moodle-plugin-dev/*moodle/local/obf/tests*

## 1: clone Moodle and Moodle Docker
Requirements for this step on your Linux machine:
- Docker (CLI), e.g.: https://docs.docker.com/engine/install/ubuntu/
- Git, e.g. ```sudo apt install git```
- PHP CLI and possible dependencies, e.g. ```sudo apt install php```

### A: Install Moodle Docker
```bash
cd ~/moodle-plugin-dev
# Clone Moodle Docker official repository
git clone https://github.com/moodlehq/moodle-docker.git
```
Moodle Docker is now cloned in ~/moodle-plugin-dev/moodle-docker.

### B: Install Moodle 
```bash
cd ~/moodle-plugin-dev
# Clone Moodle official repository
git clone https://github.com/moodle/moodle.git
```
Moodle is now cloned in ~/moodle-plugin-dev/moodle

### C: Select desired Moodle version
Checkout the desired Moodle version. Selected version will be run in your Moodle Docker. 
```bash
# Go to your version controlled Moodle folder
cd ~/moodle-plugin-dev/moodle
# List available version branches
git branch -r | grep MOODLE_
# Checkout the desired version
git checkout -b MOODLE_405_STABLE origin/MOODLE_405_STABLE
```

### D: Configure Moodle & Moodle Docker
#### i: Configure Moodle Docker WWWROOT and DB environment variables
To tell Moodle Docker where Moodle is located, run: ```export MOODLE_DOCKER_WWWROOT=~/moodle-plugin-dev/moodle```
To tell Moodle Docker what DB is used run: ```export MOODLE_DOCKER_DB=mariadb```
#### ii: Copy Moodle Docker settings to Moodle
```bash
cd ~/moodle-plugin-dev/moodle-docker/
# Ensure customized config.php for the Docker containers is in place
cp config.docker-template.php ../moodle/config.php
```
#### iii: Possible further configurations
You can now try to build & start Moodle Docker. If you ran to further issues, here's some things to consider: 
- Check Moodle Docker Quick start instructions for further assistance: https://github.com/moodlehq/moodle-docker
- Check that ~/moodle-plugin-dev/*moodle/config.php* and Moodle Docker DB configuration file ~/moodle-plugin-dev/*moodle-docker/db.*selecteddbtype.*yml* have the same values used for dbname, dbuser, dbpass and dbtype. You should probably be using MariaDB. 
- Check that Moodle data and PHPUnit data folder paths and folders exist and have permissions. Check your Moodle config.php has something like: 
    ```php
    $CFG->dataroot  = '/var/www/moodledata';
    $CFG->phpunit_prefix = 'tst_';
    $CFG->phpunit_dataroot = '/var/www/moodletestdata';
    ```

### E: Start Moodle Docker and finish Moodle installation in the browser
When you have your desired Moodle Version selected and Moodle & Moodle Docker settings configured,
build & start Moodle Docker: ```~/moodle-plugin-dev/moodle-docker/bin/moodle-docker-compose up -d```
Finish the Moodle installation on browser (localhost:8000) and use the DB settings you just configured. 

## 2. Install OBF Moodle plugin
```bash
# Go to the folder where the plugin should be installed
cd ~/moodle-plugin-dev/moodle/local/
# Clone OBF Moodle plugin
git clone https://github.com/openbadgefactory/moodle-local_obf.git
```
Plugin will be installed in version controlled ~/moodle-plugin-dev/moodle/local/obf directory. 

## 3. Initialize & Run PHPUnit tests 
Start Moodle Docker if it's not done already: ```~/moodle-plugin-dev/moodle-docker/bin/moodle-docker-compose up -d``` 
Now you can list your running Docker containers: ```docker ps```
Log into webserver container, ie. : ```docker exec -it moodle-docker-webserver-1 bash```
Before you can run PHPUnit tests here with the command: ```vendor/bin/phpunit --testsuite local_obf_testsuite```
you will likely need to initialize PHPUnit and ensure it can find the OBF Moodle plugin’s test files. 

### A. Initialize PHPUnit
In Docker webserver container, initialize PHPUnit. Run inside the container: ```php admin/tool/phpunit/cli/init.php```
If Composer is not installed already in Moodle Docker webserver container, do that first: ```composer install```

### B. Make sure PHPUnit finds unit tests
In Docker webserver container, in PHPUnit configuration file /var/www/html/phpunit.xml OBF Moodle plugin's directory suffix is probably wrong.
To fix this, run following commands inside webserver container:
```bash
git config --global --add safe.directory /var/www/html
sed -i 's|<directory suffix="_test\.php">local/obf/tests</directory>|<directory suffix="_testcase.php">local/obf/tests</directory>|' /var/www/html/phpunit.xml
```

### Run PHPUnit tests 
In the Docker webserver container, make sure you are in /var/www/html before running these commands. 
In Docker webserver container, run PHPUnit tests with the command: ```vendor/bin/phpunit --testsuite local_obf_testsuite```
To execute single PHPUnit test, run: ```vendor/bin/phpunit /var/www/html/local/obf/tests/local_obf_[name_of_the_test]_testcase.php```

### Versions
This README is created using these versions:
- Docker: 28.2.2
- Moodle: 4.5.5+
- OBF Moodle plugin: 1.1.1
- PHP (WSL host): 8.3.6
- PHP (Docker container): 8.2.28
- PHPUnit: 9.6.18
