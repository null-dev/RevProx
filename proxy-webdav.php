<?php
/**
 * PHP Webdav Streaming Reverse Proxy (RevProx) by: NULLDEV
 **/

/*
 * Username and password to webdav
 */
$USERNAME = "";
$PASSWORD = "";

/*
Add extra headers to be sent to the server:

Example:
array(
	'X-Example-Header: somevalue',
	'X-RevProx: is_awesome'
)

Do not delete the authorization header unless your webdav does not require authorization (or if you wish for your clients to authenticate).
*/
$HEADERS = array(
	"Authorization: Basic " . base64_encode($USERNAME . ":" . $PASSWORD)
);

/* Target server (can include path) */
$TARGET = "http://webdav.example.com/";

/* Customize this if you want your own path mapping logic (advanced users) */
$URL = "";
$PATH = "";

/* === END OF CUSTOMIZATION === */

if($URL == "") {
	if(isset($_GET["q"])) {
		$PATH = $_GET["q"];
	} else {
		$PATH = $_SERVER['REQUEST_URI'];
	}
	$URL = "$TARGET$PATH";
}

class ResponseProcessor {
    function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_write($data) {
	set_time_limit(300); //5 mins max until next write
	echo $data;
        return strlen($data);
    }
}

stream_wrapper_register("rp", "ResponseProcessor") or die("Failed to register protocol");

$postData = NULL;
function confReq($ch) {
	global $HEADERS;

	//Set custom headers
	curl_setopt($ch, CURLOPT_HTTPHEADER, $HEADERS);

	//POST support
	if($_SERVER['REQUEST_METHOD'] == "POST") {
		if($postData == NULL) {
			$postData = tmpfile();
			file_put_contents("php://input", $postData);
		}
		frewind($postData);

		curl_setopt($ch, CURLOPT_PUT, 1);
		curl_setopt($ch, CURLOPT_INFILESIZE, filesize($postData));
		curl_setopt($ch, CURLOPT_INFILE, ($in=$postData));
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	}
}

//LS if folder
if($URL[strlen($URL) - 1] == "/") {
	$ch = curl_init();
	confReq($ch);
	curl_setopt($ch, CURLOPT_URL, $URL);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	//Override headers
	curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($HEADERS, array("Depth: 1")));

	$resp = curl_exec($ch);
	curl_close($ch);

	$xml = simplexml_load_string($resp);
	$ns = $xml->getNamespaces(true);

	$items = $xml->children($ns["d"]);

	$epath = htmlspecialchars($PATH);
	echo "<html><head><title>Index of $epath</title></head>"
	. "<body bgcolor='white'>" . "<h1>Index of $epath</h1><hr><pre><a href='../'>../</a>\n";

	$first = true;
	foreach($items as $item) {
		$value = $item->propstat->prop;
		$dir = sizeof($value->resourcetype->children($ns["d"])) > 0;
		$url = rawurlencode($value->displayname);
		if($dir) {
			$url = "$url/";
		}
		if(!$first) {
			echo "<a href='$url'>" . htmlspecialchars($value->displayname) . "</a>\n";
		} else {
			$first = false;
		}
	}

	echo "</pre><hr></body></html>";

	die;
} else {
	//SERVE HEADERS
	{
		//Grab header info
		$ch = curl_init();
		confReq($ch);
		curl_setopt($ch, CURLOPT_URL, $URL);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		$headers = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		$data = [];
		$headers = explode(PHP_EOL, $headers);
		foreach ($headers as $row) {
			$parts = explode(':', $row);
			if (count($parts) === 2) {
			    $data[trim($parts[0])] = trim($parts[1]);
			}
		}

		//Serve header info
		http_response_code($code);

		//Send headers
		foreach ($data as $key => $value) {
			$ck = strtolower(trim($key));
			if($ck != "connection" and $ck != "server") {
				header("$key: $value");
			}
		}

		header("X-Powered-By: ND-RevProx");
	}

	//SERVE ACTUAL CONTENT BELOW

	$fp = fopen("rp://ROOT", "r+");

	$ch = curl_init();
	confReq($ch);
	curl_setopt($ch, CURLOPT_URL, $URL);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_BUFFERSIZE, 4096);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_FILE, $fp);

	curl_exec($ch);

	curl_close($ch);

	fclose($fp);
}
?>
