***REMOVED***
/*
 * © Copyright IBM Corp. 2013
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at:
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
* implied. See the License for the specific language governing
* permissions and limitations under the License.
*/
defined('SBT_SDK') OR exit('Access denied.');

use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Exception\DescriptionBuilderException;

/**
 * The proxy handles authentication with IBM services.
 * 
 * @author Benjamin Jakobus
 *
 */
// Load settings object and default configuration
require_once BASE_PATH . '/config.php';
include_once BASE_PATH . '/autoload.php';

class Proxy extends BaseController 
{
	
	/**
	 * Routes requests.
	 * 
	 * @param string server			The URL of the server to which to re-direct the request to. Uses SBTSettings if none given.
	 */
	public function route($server = null) 
	{				
		$this->loadModel('SBTSettings');
		$this->loadModel('SBTCredentialStore');
		
		$proxyHelper = new SBTProxyHelper();
		$store = SBTCredentialStore::getInstance();	
		
		$settings = new SBTSettings();

		$endpointName = $proxyHelper->determineEndpoint();

		if (!isset($_REQUEST["_redirectUrl"])) {
			// Request to check if the user is authenticated
			if (isset($_REQUEST["isAuthenticated"])) {
				$_REQUEST["_redirectUrl"] = '/files/basic/api/myuserlibrary/feed'; //used to be /connections/files/basic/api/myuserlibrary/feed
				$_SERVER['REQUEST_METHOD'] = 'GET';
			} else if (isset($_REQUEST["basicAuthLogout"])) {
				// Logout request
				$store->deleteBasicAuthCredentials($endpointName);
			} 
			 if (isset($_REQUEST["OAuthLogout"])) {	
			 	$store->deleteOAuthCredentials($endpointName);

				$timestamp = time();
				unset($_COOKIE['IBMSBTKOAuthLogin']);
				setcookie('IBMSBTKOAuthLogin', "", $timestamp - 604800);
				return;
			} else {
				return;
			}
			if (isset($_REQUEST["basicAuthLogout"])) {
				return;
			}
		}
		
		// Handle any file operations
		// If file operations exist, then control flow
		// will be interrupted and route() will be called
		// again 
		if ($this->fileOperations()) {
			return;
		}
		
		
		$url = $_REQUEST["_redirectUrl"];
		$url = str_replace("/connections/", "", $url);

		if (isset($_REQUEST['basicAuthRequest']) && $_REQUEST['basicAuthRequest'] == 'true') {
			$store->storeBasicAuthUsername($_POST['username'], $endpointName);
			$store->storeBasicAuthPassword($_POST['password'], $endpointName);
			$result = array('status' => 200, 'result' => true);
			print_r(json_encode($result));
			return;
		}
	
		$method = $_SERVER['REQUEST_METHOD'];

		$options = $proxyHelper->getOptions();
	
		$response = null;
		$body = file_get_contents('php://input');
		$endpoint = null;
		
		if ($server == null) {
			$server = $settings->getURL($endpointName);
		}
		
		$method = $_SERVER['REQUEST_METHOD'];
	
		$forwardHeader = $proxyHelper->getHeader($method);

		if ($settings->getAuthenticationMethod($endpointName) == "basic") {
			$endpoint = new SBTBasicAuthEndpoint();
		} else if ($settings->getAuthenticationMethod($endpointName) == "oauth2") {
			$endpoint = new SBTOAuth2Endpoint();
		} else if ($settings->getAuthenticationMethod($endpointName) == "oauth1") {	
			$endpoint = new SBTOAuth1Endpoint();
		}

		// ...yes, this is ugly
		$containsServerURL = false;

		if (strpos($url, '/https/') == 0 || strpos($url, 'https/') == 0) {
			$url = str_replace('/https/', 'https://', $url);
			$url = str_replace('https/', 'https://', $url);
			$result = parse_url($url);
			if (isset($result['scheme'])) {
				$url = str_replace($server, '', $url);
				$containsServerURL = true;
			}
		} else if (strpos($url, '/http/') == 0 || strpos($url, 'http/') == 0) {
			$url = str_replace('/http/', 'http://', $url);
			$url = str_replace('http/', 'http://', $url);
			$url = str_replace('/https/', 'https://', $url);
			$result = parse_url($url);
			if (isset($result['scheme'])) {
				$url = str_replace($server, '', $url);
				$containsServerURL = true;
			}
		}
		
		// Make request
 		$response = $endpoint->makeRequest($server, $url, $method, $options, $body, $forwardHeader, $endpointName);

 		// Print response
		$proxyHelper->outputResponse($response, $url, $containsServerURL);
	}
	
	/**
	 * Re-writes the URL for the file download request (because the files API does something funny with the
	 * URL generation) and then routes the requests.
	 *
	 * @author Benjamin Jakobus
	 */
	public function fileOperations() 
	{
	
		if (isset($_GET["_redirectUrl"])) {
			if (strpos($_GET["_redirectUrl"], '/DownloadFile/') !== false) {
				$url = $_GET['_redirectUrl'];
				
				// Extract library ID and file ID
				$keys = parse_url($url); 
				$path = explode("/", $keys['path']);
	
				$libraryID = $path[sizeof($path) - 1];
				$fileID = $path[sizeof($path) - 2];
				
				// Create new URL
				$url = "/files/basic/api/library/" . $libraryID . "/document/" . $fileID . "/media";
				
				// Update request
				$_REQUEST['_redirectUrl'] = $url;
				$_GET['_redirectUrl'] = $url;
				$_REQUEST['actionType'] = 'download';
				
				// Route
				$this->route();
				return true;
			} else if (strpos($_GET["_redirectUrl"], '/UploadFile/') !== false) {
				$path = explode("/", $_GET["_redirectUrl"]);
				$slug = $path[sizeof($path) - 1];
				$_GET['slug'] = $slug;
				
				// Create new URL
				$url = "/files/basic/api/myuserlibrary/feed?";
				
				if (isset($_GET['visibility'])) {
					$url .= 'visibility=' . $_GET['visibility'];
				} else {
					$url .= 'visibility=private';
				}
				
				if (isset($_GET['tag'])) {
					$url .= '&tag=' . $_GET['tag'];
				}
				
				if (isset($_GET['commentNotification'])) {
					$url .= '&commentNotification=' . $_GET['commentNotification'];
				} else {
					$_POST['commentNotification'] = 'on';
					$url .= 'visibility=private';
				}
				
				$milliseconds = round(microtime(true) * 1000);
				if (isset($_GET['created'])) {
					$_POST['created'] = $_GET['created'];
					$url .= '&commentNotification=' . $_GET['commentNotification'];
				} else {
					$_POST['created'] = $milliseconds;
					$url .= 'visibility=private';
				}
				
				
				if (isset($_GET['includePath'])) {
					$_POST['includePath'] = $_GET['includePath'];
					$url .= '&commentNotification=' . $_GET['commentNotification'];
				} else {
					$_POST['includePath'] = 'true';
					$url .= 'visibility=private';
				}
				
				if (isset($_GET['mediaNotification'])) {
					$_POST['mediaNotification'] = $_GET['mediaNotification'];
					$url .= '&commentNotification=' . $_GET['commentNotification'];
				} else {
					$_POST['mediaNotification'] = 'off';
					$url .= 'visibility=private';
				}
				
				if (isset($_GET['modified'])) {
					$_POST['modified'] = $_GET['modified'];
					$url .= '&commentNotification=' . $_GET['commentNotification'];
				} else {
					$_POST['modified'] = $milliseconds;
					$url .= 'visibility=private';
				}
				
				if (isset($_GET['propagate'])) {
					$_POST['propagate'] = $_GET['propagate'];
					$url .= '&commentNotification=' . $_GET['commentNotification'];
				} else {
					$_POST['propagate'] = 'false';
					$url .= 'visibility=private';
				}
				
				if (isset($_GET['sharePermission'])) {
					$_POST['sharePermission'] = $_GET['sharePermission'];
					$url .= '&commentNotification=' . $_GET['commentNotification'];
				} else {
					$_POST['sharePermission'] = 'Edit';
					$url .= 'visibility=private';
				}
				
				if (isset($_GET['shareSummary'])) {
					$_POST['shareSummary'] = $_GET['shareSummary'];
					$url .= '&commentNotification=' . $_GET['commentNotification'];
					
				} else {
					$_POST['shareSummary'] = 'NA';
					$url .= 'visibility=private';
				}
			
				// Update request
				$_REQUEST['_redirectUrl'] = $url;
				$_GET['_redirectUrl'] = $url;
				
				
				// Route
				$this->route();
				return true;
			} else if (strpos($_GET["_redirectUrl"], '/UploadCommunityFile/') !== false) {
				$url = $_GET['_redirectUrl']; 
				
				// Extract library ID and file ID
				$keys = parse_url($url);
				$path = explode("/", $keys['path']);
				
				$communityID = $path[sizeof($path) - 1];
				$fileID = $path[sizeof($path) - 2];
				
				// Create new URL
				$url = "/files/basic/api/communitylibrary/" . $communityID . "/feed";
				// Update request
				$_REQUEST['_redirectUrl'] = $url;
				$_GET['_redirectUrl'] = $url;
				
				// Route
				$this->route();
				return true;
			}
			
		}
		return false;
	}
	

}
?>