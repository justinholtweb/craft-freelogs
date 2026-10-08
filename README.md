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

## Large log files

Logs are read as a stream and only the page being shown is held in memory, so a multi-hundred-megabyte log opens in a few megabytes of RAM. Each view still reads through the whole file to count its entries — about 1.5 seconds per 300 MB, and roughly twice that with a search. A single entry longer than 64 KB is cut short in the viewer; download the file to see all of it.

## Permissions

Freelog adds two permissions under user/group settings:

- **View logs** — open, search, download and tail log files. Logs hold request data, email addresses and stack traces, so treat this as close to admin-level access.
- **Clear logs** (nested under View logs) — empty a log file.

Admins have both. Before 5.0.5 a single **Access Freelog** permission did both jobs; it is now View logs, so grant Clear logs to anyone who should still be able to clear.

## Usage

Navigate to **Freelog** in the control panel sidebar. Select a log file to view its contents. Use the search bar and level filter to find specific entries. Click **Download** to save a log file locally, or **Clear** to empty it.
