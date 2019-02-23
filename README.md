# ttrss-tumblr-gdpr
Plugin for [Tiny Tiny RSS](https://tt-rss.org/) to workaround GDPR in Europe.

# INSTALLATION
- Download from [Releases](https://github.com/GregThib/ttrss-tumblr-gdpr/releases) or git clone
- Rename the downloaded, extracted directory to `tumblr_gdpr`
- Put the tumblr_gdpr into your plugins.local if you have (or plugins if not). The path to `init.php` should be this: `[TTRSS install dir]/plugins.local/tumblr_gdpr/init.php`
- Activate it in the UI panel configurator, or by config.php for the whole instance
- Optionnally add some domains hosted by Tumblr with another URI in : "prefs/config/Tumblr GDPR"
