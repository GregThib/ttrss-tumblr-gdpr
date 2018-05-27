<?php
class Tumblr_GDPR extends Plugin {
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

	}

	private function is_supported($url) {
		$supported = $this->host->get($this, "supported", array());
		$supported = array_map(function($a) {return preg_quote($a, '/');}, $supported);
		$preg='/\.tumblr\.com|' . implode('|', $supported) . '/i';

		return preg_match($preg, $url);
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
		if(version_compare(curl_version()['version'], '7.10.8') >= 0)
			curl_setopt($ch, CURLOPT_IPRESOLVE,  CURL_IPRESOLVE_V4);

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

		// Get the tumblr form key
		curl_setopt($ch, CURLOPT_URL, 'https://www.tumblr.com/privacy/consent');
		curl_setopt($ch, CURLOPT_HEADER, false);
		$ret = @curl_exec($ch);
		$tumblr_form_key = '';
		if (preg_match('/id="tumblr_form_key" content="([^"]*)">/', $ret, $matches)) {
			$tumblr_form_key = $matches[1];
		}

		// Next, get cookie, yumi
	        $vendor_consents = "granted_purposes%3D%26denied_purposes%3D1%2C2%2C3%2C4%2C5%26granted_vendor_ids%3D%26denied_vendor_ids%3D147%2C57%2C50%2C39%2C93%2C22%2C74%2C130%2C6%2C27%2C81%2C32%2C122%2C128%2C36%2C10%2C77%2C24%2C85%2C91%2C71%2C118%2C1%2C78%2C61%2C67%2C97%2C109%2C95%2C79%2C34%2C112%2C69%2C127%2C140%2C11%2C60%2C52%2C86%2C111%2C68%2C45%2C114%2C89%2C21%2C23%2C159%2C70%2C25%26granted_vendor_oids%3D%26denied_vendor_oids%3D1%2C2%2C3%2C4%2C5%2C6%2C7%2C8%2C9%2C10%2C11%2C12%2C13%2C14%2C15%2C16%2C17%2C18%2C19%2C20%2C22%2C24%2C25%2C26%2C29%2C30%2C32%2C33%2C34%2C35%2C36%2C37%2C38%2C39%2C40%2C41%2C42%2C43%2C44%2C45%2C46%2C47%2C48%2C49%2C50%2C51%2C52%2C55%2C56%2C57%2C344%2C58%2C59%2C60%2C61%2C62%2C64%2C65%2C66%2C67%2C68%2C69%2C70%2C71%2C72%2C73%2C74%2C75%2C76%2C77%2C78%2C79%2C80%2C81%2C82%2C83%2C84%2C85%2C86%2C88%2C89%2C90%2C91%2C92%2C93%2C94%2C95%2C96%2C97%2C98%2C99%2C100%2C101%2C102%2C103%2C104%2C105%2C106%2C107%2C108%2C109%2C110%2C111%2C112%2C113%2C114%2C115%2C116%2C117%2C118%2C119%2C120%2C121%2C122%2C123%2C124%2C125%2C126%2C127%2C128%2C129%2C130%2C131%2C132%2C133%2C134%2C135%2C136%2C137%2C138%2C139%2C141%2C142%2C143%2C144%2C145%2C146%2C147%2C148%2C149%2C150%2C151%2C152%2C153%2C154%2C155%2C156%2C157%2C158%2C159%2C160%2C163%2C164%2C165%2C166%2C167%2C168%2C169%2C170%2C171%2C172%2C173%2C174%2C175%2C176%2C177%2C178%2C179%2C180%2C181%2C182%2C183%2C184%2C185%2C186%2C187%2C188%2C189%2C190%2C191%2C192%2C193%2C194%2C195%2C196%2C197%2C199%2C200%2C201%2C202%2C203%2C204%2C206%2C207%2C208%2C209%2C210%2C211%2C212%2C213%2C214%2C215%2C216%2C217%2C218%2C219%2C220%2C221%2C224%2C225%2C226%2C227%2C228%2C229%2C230%2C231%2C232%2C233%2C234%2C235%2C236%2C237%2C238%2C239%2C240%2C241%2C242%2C243%2C244%2C245%2C246%2C247%2C248%2C249%2C250%2C251%2C252%2C253%2C254%2C255%2C256%2C258%2C259%2C260%2C261%2C262%2C263%2C264%2C265%2C266%2C267%2C268%2C269%2C270%2C271%2C272%2C273%2C274%2C275%2C276%2C277%2C278%2C279%2C280%2C281%2C283%2C284%2C285%2C286%2C287%2C288%2C289%2C290%2C291%2C292%2C293%2C294%2C295%2C298%2C299%2C300%2C301%2C302%2C304%2C305%2C306%2C307%2C308%2C309%2C310%2C311%2C312%2C313%2C314%2C315%2C316%2C317%2C318%2C319%2C320%2C321%2C323%2C324%2C325%2C326%2C327%2C328%2C330%2C331%2C332%2C333%2C334%2C335%2C336%2C337%2C338%2C339%2C340%2C341%2C342%2C343%2C0%26oath_vendor_list_version%3D5%26vendor_list_version%3D19";
		$payload = json_encode(array(
	                "vendor_consents" => $vendor_consents,
			"eu_resident" => true,
			// If I set these to false, tumblr will keep redirecting to the consent form...
			// but they're disabled in the vendor_consents blob
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
			"Referer: " . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
			);
		curl_setopt($ch, CURLOPT_URL, 'https://www.tumblr.com/svc/privacy/consent');
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, $parse_cookie);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_COOKIESESSION, true);
		$ret = @curl_exec($ch);
		$ret = @json_decode($ret, true);

		// Next, get the normal page
		if(isset($ret['redirect_to'])) $url = $ret['redirect_to'];
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array());
		curl_setopt($ch, CURLOPT_COOKIE, $cookie);
		curl_setopt($ch, CURLOPT_HEADER, true);
		// curl_setopt($ch, CURLOPT_HEADERFUNCTION, /*how to unset ?*/ );
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

	// Subscribe to the feed, but post consent data before
	function hook_subscribe_feed($contents, $fetch_url, $auth_login, $auth_pass) {
		//if ($contents) return $contents;
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
		if (!$this->is_supported($fetch_url)) return $feed_data;

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
