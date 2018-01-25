# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
## [0.15.1] - 2018-01-25
### Changed
- Adhere to base database changes

## [0.15.0] - 2017-12-28
### Added
- DF-1224 Added ability to set different default limits (max_records_returned) per service
- Added package discovery
- DF-1186 Added exceptions for missing data when generating relationships
### Changed
- Separated resources from resource handlers

## [0.14.0] - 2017-11-03
- Upgrade Swagger to OpenAPI 3.0 specification

## [0.13.0] - 2017-09-18
### Added
- DF-1060 Support for data retrieval (GET) caching and configuration

## [0.12.0] - 2017-08-17
### Changed
- Reworking API doc usage and generation
- Bug fixes for service caching
- Set config-based cache prefix

## [0.11.0] - 2017-07-27
- Cleanup service config usage

## [0.10.0] - 2017-06-05
### Changed
- Cleanup - removal of php-utils dependency

## [0.9.0] - 2017-04-21
### Changed
- Update to config handling from latest df-core

## [0.8.0] - 2017-03-03
- Major restructuring to upgrade to Laravel 5.4 and be more dynamically available

## [0.7.0] - 2017-01-16
### Changed
- Adhere to refactored df-core, see df-database
- Cleanup schema management issues

## [0.6.0] - 2016-11-17
### Changed
- Virtual relationships rework to support all relationship types
- DB base class changes to support field configuration across all database types
- Database create and update table methods to allow for native settings

## [0.5.0] - 2016-10-03
### Changed
- Update interface changes in df-core

## [0.4.0] - 2016-08-21
### Changed
- General cleanup from declaration changes in df-core for service doc and providers

## [0.3.1] - 2016-07-08
### Changed
- General cleanup from declaration changes in df-core.

## [0.3.0] - 2016-05-27
### Changed
- Moved seeding functionality to service provider to adhere to df-core changes.

### Fixed
- GET and DELETE now handle 404 properly
- Truncate table command fixed

## [0.2.0] - 2016-01-29
### Added

### Changed
- **MAJOR** Updated code base to use OpenAPI (fka Swagger) Specification 2.0 from 1.2

### Fixed

## [0.1.1] - 2015-12-18
### Changed
- Sync up with changes in df-core for schema classes

## 0.1.0 - 2015-10-24
First official release working with the new [df-core](https://github.com/dreamfactorysoftware/df-core) library.

[Unreleased]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.15.1...HEAD
[0.15.1]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.15.0...0.15.1
[0.15.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.14.0...0.15.0
[0.14.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.13.0...0.14.0
[0.13.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.12.0...0.13.0
[0.12.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.11.0...0.12.0
[0.11.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.10.0...0.11.0
[0.10.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.9.0...0.10.0
[0.9.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.8.0...0.9.0
[0.8.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.5.0...0.6.0
[0.5.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.3.1...0.4.0
[0.3.1]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/dreamfactorysoftware/df-couchdb/compare/0.1.0...0.1.1
