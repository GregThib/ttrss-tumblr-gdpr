<?php
class Tumblr_GDPR extends Plugin {
	private $host;
	private $supported = array();

	function about() {
		return array(1.2,
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
		global $fetch_last_error;
		global $fetch_last_error_code;
		global $fetch_last_content_type;
		global $fetch_last_error_content;
		global $fetch_effective_url;

		$debug_enabled = defined('DAEMON_EXTENDED_DEBUG') || clean($_REQUEST['xdebug']);
		_debug_suppress(!$debug_enabled);
		_debug("Tumblr_GDPR: start", $debug_enabled);

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
		if(version_compare(curl_version()['version'], '7.10.8') >= 0)
			curl_setopt($ch, CURLOPT_IPRESOLVE,  CURL_IPRESOLVE_V4);

		// Download limit
		curl_setopt($ch, CURLOPT_NOPROGRESS, false);
		curl_setopt($ch, CURLOPT_BUFFERSIZE, 256);
		curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($curl_handle, $download_size, $downloaded, $upload_size, $uploaded) {
			if(defined('MAX_DOWNLOAD_FILE_SIZE')){
				return ($downloaded > MAX_DOWNLOAD_FILE_SIZE) ? 1 : 0; // if max size is set, abort when exceeding it
			} else {
				return 0;
			}
		});

		if (!ini_get("open_basedir")) {
			curl_setopt($ch, CURLOPT_COOKIEJAR, "/dev/null");
		}

		if (defined('_HTTP_PROXY')) {
			curl_setopt($ch, CURLOPT_PROXY, _HTTP_PROXY);
		}

		if ($login && $pass)
			curl_setopt($ch, CURLOPT_USERPWD, "$login:$pass");

		// I . First, get form key id
		$tumblr_form_key = '';
		curl_setopt($ch, CURLOPT_URL, 'https://www.tumblr.com/privacy/consent');
		curl_setopt($ch, CURLOPT_HEADER, false);
		$ret = @curl_exec($ch);
		if (preg_match('/id="tumblr_form_key" content="([^"]*)">/', $ret, $matches)) {
			$tumblr_form_key = $matches[1];
		}
		_debug("Tumblr_GDPR: form_key=$tumblr_form_key", $debug_enabled);

		// II . Next, get cookie, yumi
		// - maybe a way to generate this list ?
		$vendor_consents = urlencode(
			"granted_purposes=&" .
			"denied_purposes=1,2,3,4,5&" .
			"granted_vendor_ids=&" .
			"denied_vendor_ids=147,57,50,39,93,22,74,130,6,27,81,32,122,128,36,10,77,24,85,91,71,118,1,78,61,67,97,109,95,79,34,112,69,127,140,11,60,52,86,111,68,45,114,89,21,23,159,70,25&" .
			"granted_vendor_oids=&" .
			"denied_vendor_oids=1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,22,24,25,26,29,30,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,55,56,57,344,58,59,60,61,62,64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,88,89,90,91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116,117,118,119,120,121,122,123,124,125,126,127,128,129,130,131,132,133,134,135,136,137,138,139,141,142,143,144,145,146,147,148,149,150,151,152,153,154,155,156,157,158,159,160,163,164,165,166,167,168,169,170,171,172,173,174,175,176,177,178,179,180,181,182,183,184,185,186,187,188,189,190,191,192,193,194,195,196,197,199,200,201,202,203,204,206,207,208,209,210,211,212,213,214,215,216,217,218,219,220,221,224,225,226,227,228,229,230,231,232,233,234,235,236,237,238,239,240,241,242,243,244,245,246,247,248,249,250,251,252,253,254,255,256,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,283,284,285,286,287,288,289,290,291,292,293,294,295,298,299,300,301,302,304,305,306,307,308,309,310,311,312,313,314,315,316,317,318,319,320,321,323,324,325,326,327,328,330,331,332,333,334,335,336,337,338,339,340,341,342,343,0&" .
			"oath_vendor_list_version=5&" .
			"vendor_list_version=19");
		$payload = json_encode(array(
			"vendor_consents" => $vendor_consents,
			"eu_resident" => true,
			"gdpr_consent_core" => true,
			"gdpr_consent_first_party_ads" => true,
			"gdpr_consent_search_history" => true,
			"gdpr_consent_third_party_ads" => true,
			"gdpr_is_acceptable_age" => true,
			"redirect_to" => $url));
		$headers = array(
			"Content-Type: application/json",
			"X-Requested-With: XMLHttpRequest",
			"X-tumblr-form-key: $tumblr_form_key",
			"Origin: https://www.tumblr.com",
			"Referer: " . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
		curl_setopt($ch, CURLOPT_URL, 'https://www.tumblr.com/svc/privacy/consent');
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, $parse_cookie);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_COOKIESESSION, true);
		$ret = @curl_exec($ch);
		$ret = @json_decode($ret, true);
		_debug("Tumblr_GDPR: cookie=$cookie", $debug_enabled);

		// III . Now, get the normal page
		if(isset($ret['redirect_to'])) $url = $ret['redirect_to'];
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array());
		// curl_setopt($ch, CURLOPT_HEADERFUNCTION, /*how to unset ?*/ );
		curl_setopt($ch, CURLOPT_HTTPGET, true);
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
		_debug("Tumblr_GDPR: http_code=$http_code", $debug_enabled);

		if ($http_code != 200) {

			if (curl_errno($ch) != 0) {
				$fetch_last_error .=  "; " . curl_errno($ch) . " " . curl_error($ch);
			}

			$fetch_last_error_content = $contents;
			curl_close($ch);
			_debug("Tumblr_GDPR: error=$fetch_last_error", $debug_enabled);
			return false;
		}

		if (!$contents) {
			$fetch_last_error = curl_errno($ch) . " " . curl_error($ch);
			curl_close($ch);
			_debug("Tumblr_GDPR: error=$fetch_last_error", $debug_enabled);
			return false;
		}

		curl_close($ch);

		return $contents;
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
