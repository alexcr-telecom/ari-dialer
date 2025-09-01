# ARI Dialer

Asterisk ARI Auto-Dialer Application with WebSocket integration.

## Features

- Real-time WebSocket connection to Asterisk ARI
- Auto-reconnection with backoff logic
- Call management and dialing capabilities
- Web-based management interface
- MySQL database integration
- Comprehensive logging

## Requirements

- PHP 7.4+
- Asterisk 16+ with ARI enabled
- MySQL/MariaDB
- Composer for dependency management

## Installation

1. Clone the repository
2. Run `composer install` to install dependencies
3. Copy `config/config.php.example` to `config/config.php`
4. Configure database and ARI credentials in `config/config.php`
5. Import the database schema
6. Set up directory permissions for `uploads/`, `logs/`, `recordings/`

## Usage

### Start the ARI Service

```bash
php services/ari-service.php
```

### Run as daemon with systemd

```bash
sudo systemctl enable ari-dialer
sudo systemctl start ari-dialer
```

## Recent Updates

- ✅ Fixed WebSocket connection stability issues
- ✅ Implemented proper ReactPHP WebSocket client
- ✅ Added automatic reconnection logic
- ✅ Replaced polling with real-time event handling

## License

MIT License
