# Freelog for Craft CMS 5

View, search, filter, and download server logs directly from the Craft CMS control panel.

## Features

- Browse all log files in your Craft `storage/logs` directory
- Search log contents by keyword
- Filter entries by log level (emergency, alert, critical, error, warning, notice, info, debug)
- Download log files
- Clear log files
- Auto-refresh / tail mode
- Native Craft CP interface

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

Install via Composer:

```bash
composer require jholt/craft-freelog
```

Then install the plugin from the Craft control panel under **Settings > Plugins**, or via CLI:

```bash
php craft plugin/install freelog
```

## Permissions

Freelog adds an **Access Freelog** permission under user/group settings. Only users with this permission (or admins) can access the log viewer.

## Usage

Navigate to **Freelog** in the control panel sidebar. Select a log file to view its contents. Use the search bar and level filter to find specific entries. Click **Download** to save a log file locally, or **Clear** to empty it.
