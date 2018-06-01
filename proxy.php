<?php
/**
 * PHP Streaming Reverse Proxy (RevProx) by: NULLDEV
 **/

/*
Add extra headers to be sent to the server:

Example:
array(
	'X-Example-Header: somevalue',
	'X-RevProx: is_awesome'
)
*/
$HEADERS = array();

/* Target server (can include path) */
$TARGET = "https://mirrors.kernel.org/archlinux/";

/* Customize this if you want your own path mapping logic (for advanced people) */
$URL = "";

/* === END OF CUSTOMIZATION === */

if($URL == "") {
	if(isset($_GET["q"])) {
		$URL = $_GET["q"];
	} else {
		$URL = $_SERVER['REQUEST_URI'];
	}
	$URL = "$TARGET$URL";
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
		rewind($postData);

		curl_setopt($ch, CURLOPT_PUT, 1);
		curl_setopt($ch, CURLOPT_INFILESIZE, filesize($postData));
		curl_setopt($ch, CURLOPT_INFILE, ($in=$postData));
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	}
}

//SERVE HEADERS
{
	//Grab header info
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $URL);
	curl_setopt($ch, CURLOPT_NOBODY, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	confReq($ch);

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
curl_setopt($ch, CURLOPT_URL, $URL);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_BUFFERSIZE, 4096);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_FILE, $fp);
confReq($ch);

curl_exec($ch);

curl_close($ch);

fclose($fp);
?>
