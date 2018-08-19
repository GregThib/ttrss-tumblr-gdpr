<?php
class Tumblr_GDPR extends Plugin {
	private $host;
	private $supported = array();

	function about() {
		return array(2.0,
			"Fixes Tumblr feeds for GDPR compliance & consent approval (requires CURL)",
			"GTT");
	}

	function flags() {
		return array("needs_curl" => true);
	}

	function init($host) {
		$this->host = $host;

		if (function_exists("curl_init")) {
			$host->add_hook($host::HOOK_SUBSCRIBE_FEED, $this);
			$host->add_hook($host::HOOK_FEED_BASIC_INFO, $this);
			$host->add_hook($host::HOOK_FETCH_FEED, $this);
			$host->add_hook($host::HOOK_PREFS_TAB, $this);
		}

	}

	private function is_supported($url) {
		$supported = array('.tumblr.com');
		$supported = array_merge($supported, $this->host->get($this, "supported", array()));
		$supported = array_map(function($a) {return preg_quote($a, '/');}, $supported);

		return preg_match('/' . implode('|', $supported) . '/i', $url);
	}

	private function fetch_tumblr_contents($url, $login = false, $pass = false) {
		// I. give a new UA for download
		$options = array(
			'url' => $url,
			'login' => $login,
			'pass' => $pass,
			'useragent' => 'googlebot'
		);

		// II. call normal fetching method with the new UA
		return fetch_file_contents($options);
	}

	// Subscribe to the feed, but post consent data before
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function hook_subscribe_feed($contents, $fetch_url, $auth_login, $auth_pass) {
		// first, load plugin and his data
		static $kind_user = false;
		if(!$kind_user) {
			$kind_user = @$this->about()[3] ? $this->host->KIND_SYSTEM : $this->host->KIND_USER;
			$this->host->load(get_class($this), $kind_user, $_SESSION["uid"], true);
			$this->host->load_data();
		}
		if (!$this->is_supported($fetch_url)) return $contents;

		$feed_data = $this->fetch_tumblr_contents($fetch_url, $auth_login, $auth_pass);
		$feed_data = trim($feed_data);

		return $feed_data;
	}

	// Get the feed's basic info, but post consent data before
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function hook_feed_basic_info($basic_info, $fetch_url, $owner_uid, $feed, $auth_login, $auth_pass) {
		if (!$this->is_supported($fetch_url)) return $basic_info;

		$feed_data = $this->fetch_tumblr_contents($fetch_url, $auth_login, $auth_pass);
		$feed_data = trim($feed_data);

		$rss = new FeedParser($feed_data);
		$rss->init();

		if (!$rss->error()) {
			$basic_info = array(
				'title' => mb_substr($rss->get_title(), 0, 199),
				'site_url' => mb_substr(rewrite_relative_url($fetch_url, $rss->get_link()), 0, 245)
			);
		}

		return $basic_info;
	}

	// Get the feed, but post consent data before
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass) {
		if (!$this->is_supported($fetch_url)) return $feed_data;

		$feed_data = $this->fetch_tumblr_contents($fetch_url, $auth_login, $auth_pass);
		$feed_data = trim($feed_data);

		return $feed_data;
	}

	// Preference settings to add website hosted by tumblr but w/ a different URI
	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Tumblr GDPR')."\">";

		print "<p>" . __("List of domains hosted by tumblr (add your own):") . "</p>";

		print "<form dojoType=\"dijit.form.Form\">";
		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						if (transport.responseText.indexOf('error')>=0) notify_error(transport.responseText);
							else notify_info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"save\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"tumblr_gdpr\">";

		print "<table><tr><td>";
		print "<textarea dojoType=\"dijit.form.SimpleTextarea\" name=\"tumblr_support\" style=\"font-size: 12px; width: 99%; height: 500px;\">";
		print implode(PHP_EOL, $this->host->get($this, "supported", array())) . PHP_EOL;
		print "</textarea>";
		print "</td></tr></table>";

		print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".__("Save")."</button>";
		print "</form>";

		print "</div>";
	}

	function save() {
		$supported = explode("\r\n", $_POST['tumblr_support']);
		$supported = array_filter($supported);

		$this->host->set($this, 'supported', $supported);
		echo __("Configuration saved.");
	}

	function api_version() {
		return 2;
	}

}
