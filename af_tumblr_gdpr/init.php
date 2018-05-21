<?php
class Af_Tumblr_GDPR extends Plugin {
	private $host;
	private $supported = array();

	function about() {
		return array(1.0,
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

		$this->supported = array(
			'.tumblr.com',
			'shencomix.com',
			'aurevoirmadame.fr',
			'bonjourmadame.fr',
		);
	}

	private function fetch_tumblr_contents($url, $login = false, $pass = false) {
		global $fetch_last_error;
		global $fetch_last_error_code;
		global $fetch_last_content_type;
		global $fetch_last_error_content;
		global $fetch_effective_url;
		
		$cookie='';
		$parse_cookie = function($ch, $header_line) use(&$cookie) {
			if(preg_match("/^Set-Cookie: (.*)$/iU", $header_line, $matches)) {
				$cookie = $matches[1];
			}
			return strlen($header_line);
		};

		$url = ltrim($url, ' ');
		$url = str_replace(' ', '%20', $url);

		if (strpos($url, "//") === 0)
			$url = 'http:' . $url;

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, FILE_FETCH_CONNECT_TIMEOUT);
		curl_setopt($ch, CURLOPT_TIMEOUT, FILE_FETCH_TIMEOUT);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, !ini_get("open_basedir"));
		curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		//curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		curl_setopt($ch, CURLOPT_USERAGENT, SELF_USER_AGENT);
		curl_setopt($ch, CURLOPT_ENCODING, "");
		//curl_setopt($ch, CURLOPT_REFERER, $url);

		// Download limit
		curl_setopt($ch, CURLOPT_NOPROGRESS, false);
		curl_setopt($ch, CURLOPT_BUFFERSIZE, 128); // needed to get 5 arguments in progress function?
		curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($curl_handle, $download_size, $downloaded, $upload_size, $uploaded) {
			return ($downloaded > MAX_DOWNLOAD_FILE_SIZE) ? 1 : 0; // if max size is set, abort when exceeding it
		});

		if (!ini_get("open_basedir")) {
			curl_setopt($ch, CURLOPT_COOKIEJAR, "/dev/null");
		}

		if (defined('_HTTP_PROXY')) {
			curl_setopt($ch, CURLOPT_PROXY, _HTTP_PROXY);
		}

		if ($login && $pass)
			curl_setopt($ch, CURLOPT_USERPWD, "$login:$pass");

		// First, get cookie, yumi
		//$payload = array('eu_resident' => 'true', 'gdpr_consent_core' => 'true', 'redirect_to' => $url);
		$payload = array(
			"eu_resident" => "True",
			"gdpr_consent_core" => "False",
			"gdpr_consent_first_party_ads" => "False",
			"gdpr_consent_search_history" => "False",
			"gdpr_consent_third_party_ads" => "False",
			"gdpr_is_acceptable_age" => "False",
			"redirect_to" => $url);
		curl_setopt($ch, CURLOPT_URL, 'https://www.tumblr.com/svc/privacy/consent');
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, $parse_cookie);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_COOKIESESSION, true);
		$ret = @curl_exec($ch);
		$ret = @json_decode($ret, true);
		
		// Next, get the normal page
		if(isset($ret['redirect_to'])) $url = $ret['redirect_to'];
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		// curl_setopt($ch, CURLOPT_HEADERFUNCTION, /*how to unset ?*/ );
		curl_setopt($ch, CURLOPT_POST, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "");
		curl_setopt($ch, CURLOPT_COOKIE, $cookie);
		$ret = @curl_exec($ch);

		$headers_length = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$headers = explode("\r\n", substr($ret, 0, $headers_length));
		$contents = substr($ret, $headers_length);

		foreach ($headers as $header) {
			if (substr(strtolower($header), 0, 7) == 'http/1.') {
				$fetch_last_error_code = (int) substr($header, 9, 3);
				$fetch_last_error = $header;
			}
		}

		if (curl_errno($ch) === 23 || curl_errno($ch) === 61) {
			curl_setopt($ch, CURLOPT_ENCODING, 'none');
			$contents = @curl_exec($ch);
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$fetch_last_content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

		$fetch_effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

		$fetch_last_error_code = $http_code;

		if ($http_code != 200) {

			if (curl_errno($ch) != 0) {
				$fetch_last_error .=  "; " . curl_errno($ch) . " " . curl_error($ch);
			}

			$fetch_last_error_content = $contents;
			curl_close($ch);
			return false;
		}

		if (!$contents) {
			$fetch_last_error = curl_errno($ch) . " " . curl_error($ch);
			curl_close($ch);
			return false;
		}

		curl_close($ch);

		return $contents;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Feeds supported by af_tumblr_gdpr')."\">";

		print "<p>" . __("The following feeds are currently supported:") . "</p>";

		print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";
		foreach ($this->supported as $feed) {
			print "<li>$feed</li>";
		}
		print "</ul>";

		print "</div>";
	}

	// Subscribe to the feed, but post consent data before
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function hook_subscribe_feed($contents, $fetch_url, $auth_login, $auth_pass) {
                if(!$contents) {
			$contents = $this->hook_fetch_feed($contents, $fetch_url, 0, 0, 0, $auth_login, $auth_pass);
		}

		return $contents;
	}


	// Get the feed's basic info, but post consent data before
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function hook_feed_basic_info($basic_info, $fetch_url, $owner_uid, $feed, $auth_login, $auth_pass) {
		$feed_data = '';
		$feed_data = $this->hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, 0, $auth_login, $auth_pass);

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
		$preg='/' . implode('|', array_map(function($a) {return preg_quote($a, '/');}, $this->supported)) . '/i';
		if (preg_match($preg, $fetch_url)) {
			$feed_data = $this->fetch_tumblr_contents($fetch_url, $auth_login, $auth_pass);
			$feed_data = trim($feed_data);
		}

		return $feed_data;
	}

	function api_version() {
		return 2;
	}

}
