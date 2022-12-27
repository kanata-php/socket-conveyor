# Change Log
All notable changes to this project will be documented in this file.
Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## [1.1.8] - 2022-09-02

### Fixed

- Fixed bug with broadcast: listeners connection were causing message distribution, further changes regarding that.


## [1.1.7] - 2022-08-29

### Fixed

- Fixed bug with broadcast: some conditions did not match the expected message distribution.

### Added

- New actions:
    - Broadcast Action - to distribute messages to all connections in the same channel
    - Fanout Action - to distribute messages across channels

- New persistence implementations using Swoole\Channel will facilitate users to use out-of-the-box channels, user associations, and listeners.
    - SocketChannelPersistenceTable - for channels
    - SocketListenerPersistenceTable - for listeners
    - SocketUserAssocPersistenceTable - for user associations

- Updated docs

## [1.1.3] - 2022-05-08

### Added

- Improved docs.
- Added helper method at action abstraction to get current execution channel when in channel context.

## [1.1.0] - 2022-02-16

### Added

- Action routing based on message pattern
- Composer vendor binary for ws server creation out of the box
- Association between user-id and connection fd
- WS channel implementation
- Actions listening