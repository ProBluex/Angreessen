# Third-Party Libraries

This directory contains third-party libraries required by the Tolliver Agent plugin.

## Action Scheduler

The Action Scheduler library is required for background batch processing functionality.

### Installation

1. The `action-scheduler.zip` file should already be downloaded to this directory
2. Extract the ZIP file contents to create: `lib/action-scheduler/action-scheduler.php`
3. The directory structure should be:
   ```
   lib/
   ├── action-scheduler/
   │   ├── action-scheduler.php  (main entry point)
   │   ├── classes/
   │   ├── lib/
   │   └── ... (other Action Scheduler files)
   └── action-scheduler.zip
   ```

### Manual Extraction (if needed)

If the ZIP file was not automatically extracted:

```bash
cd wordpress-plugin/tolliver-agent/lib/
unzip action-scheduler.zip -d action-scheduler/
```

Or use WordPress's built-in unzip functionality:

```php
$zip = new ZipArchive;
$zip->open(AGENT_HUB_PLUGIN_DIR . 'lib/action-scheduler.zip');
$zip->extractTo(AGENT_HUB_PLUGIN_DIR . 'lib/');
$zip->close();
```

### Version

- **Current Version**: 3.8.2
- **Source**: https://downloads.wordpress.org/plugin/action-scheduler.3.8.2.zip
- **Documentation**: https://actionscheduler.org/

### Purpose

Action Scheduler enables the plugin to:
- Process large batches of posts in the background
- Allow users to close their browser during batch processing
- Provide reliable job queue management with automatic retries
- Send email notifications when batches complete or fail
