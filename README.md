# InfluxDB StatusCake

CLI tool to import StatusCake data into InfluxDB.

## Requirements

* PHP 7.1+
* Composer

## Installation

1. Clone this repository or download the latest release
2. Run `composer install` to install required dependencies
3. Copy `env.example` to `.env` in the same directory and fill all fields.
4. Run `bin/influxdb-statuscake --help` to see all available commands
5. Create a cron job to run the command(s) regularly (e.g. every 5 minutes)

## Usage

### Performance data

Command: `bin/influxdb-statuscake performance`
See: https://www.statuscake.com/api/Performance%20Data/Get%20All%20Data.md

Collects performance data from all tests:

* Test ID
* Test name
* Test type (e.g. HTTP)
* Probe location
* Probe location as country code
* Performance in ms (value)

### Uptime

Command: `bin/influxdb-statuscake uptime`
See: https://www.statuscake.com/api/Tests/Get%20All%20Tests.md

Collects uptime data from all tests:

* Test ID
* Test name
* Test type (e.g. HTTP)
* Paused state (boolean)
* Status (Up or Down)
* Uptime (1 day uptime in percentage)