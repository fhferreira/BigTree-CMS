<?
	function _localCleanup() {
		// Remove the package directory, we do it backwards because the "deepest" files are last
		$contents = @array_reverse(BigTree::directoryContents(SERVER_ROOT."cache/package/"));
		foreach ((array)$contents as $file) {
			@unlink($file);
			@rmdir($file);
		}
		@rmdir(SERVER_ROOT."cache/package/");
	}

	// See if we've hit post_max_size
	if (!$_POST["_bigtree_post_check"]) {
		$_SESSION["bigtree_admin"]["post_max_hit"] = true;
		BigTree::redirect($_SERVER["HTTP_REFERER"]);
	}
	
	// Make sure an upload succeeded
	$error = $_FILES["file"]["error"];
	if ($error == 1 || $error == 2) {
		$_SESSION["upload_error"] = "The file you uploaded is too large.  You may need to edit your php.ini to upload larger files.";
	} elseif ($error == 3) {
		$_SESSION["upload_error"] = "File upload failed.";
	}
	
	if ($error) {
		BigTree::redirect(DEVELOPER_ROOT."packages/install/");
	}
	
	// We've at least got the file now, unpack it and see what's going on.
	$file = $_FILES["file"]["tmp_name"];
	if (!$file) {
		$_SESSION["upload_error"] = "File upload failed.";
		BigTree::redirect(DEVELOPER_ROOT."extensions/install/");
	}
	
	if (!is_writable(SERVER_ROOT."cache/")) {
?>
<div class="container">
	<section>
		<h3>Error</h3>
		<p>Your cache/ directory must be writable.</p>
	</section>
</div>
<?
		$admin->stop();
	}
	
	// Clean up existing area
	_localCleanup();
	$cache_root = SERVER_ROOT."cache/package/";
	if (!file_exists($cache_root)) {
		mkdir($cache_root);
	}
	// Unzip the extension
	include BigTree::path("inc/lib/pclzip.php");
	$zip = new PclZip($file);
	$files = $zip->extract(PCLZIP_OPT_PATH,$cache_root);
	if (!$files) {
		_localCleanup();
		$_SESSION["upload_error"] = "The zip file uploaded was corrupt.";
		BigTree::redirect(DEVELOPER_ROOT."extensions/install/");
	}
	
	// Read the manifest
	$json = json_decode(file_get_contents($cache_root."manifest.json"),true);
	// Make sure it's legit
	if ($json["type"] != "extension" || !isset($json["id"]) || !isset($json["title"])) {
		_localCleanup();
		$_SESSION["upload_error"] = "The zip file uploaded does not appear to be a BigTree extension.";
		BigTree::redirect(DEVELOPER_ROOT."extensions/install/");
	}

	// Check if it's already installed
	if (sqlrows(sqlquery("SELECT * FROM bigtree_extensions WHERE id = '".sqlescape($json["id"])."'"))) {
		_localCleanup();
		$_SESSION["upload_error"] = "An extension with the id of ".htmlspecialchars($json["id"])." is already installed.";
		BigTree::redirect(DEVELOPER_ROOT."extensions/install/");
	}
	
	// Check for table collisions
	foreach ((array)$json["sql"] as $command) {
		if (substr($command,0,14) == "CREATE TABLE `") {
			$table = substr($command,14);
			$table = substr($table,0,strpos($table,"`"));
			if (sqlrows(sqlquery("SHOW TABLES LIKE '$table'"))) {
				$warnings[] = "A table named &ldquo;$table&rdquo; already exists &mdash; the table will be overwritten.";
			}
		}
	}
	// Check file permissions and collisions
	foreach ((array)$json["files"] as $file) {
		if (!BigTree::isDirectoryWritable(SERVER_ROOT.$file)) {
			$errors[] = "Cannot write to $file &mdash; please make the root directory or file writable.";
		} elseif (file_exists(SERVER_ROOT.$file)) {
			if (!is_writable(SERVER_ROOT.$file)) {
				$errors[] = "Cannot overwrite existing file: $file &mdash; please make the file writable or delete it.";
			} else {
				$warnings[] = "A file already exists at $file &mdash; the file will be overwritten.";
			}
		}
	}
?>
<div class="container">
	<summary>
		<h2>
			<?=$json["title"]?> <?=$json["version"]?>
			<small>by <?=$json["author"]["name"]?></small>
		</h2>
	</summary>
	<section>
		<?
			if (count($warnings)) {
		?>
		<h3>Warnings</h3>
		<ul>
			<? foreach ($warnings as $w) { ?>
			<li><?=$w?></li>
			<? } ?>
		</ul>
		<?
			}
			
			if (count($errors)) {
		?>
		<h3>Errors</h3>
		<ul>
			<? foreach ($errors as $e) { ?>
			<li><?=$e?></li>
			<? } ?>
		</ul>
		<p><strong>ERRORS OCCURRED!</strong> &mdash; Please correct all errors.  You may not import this module while errors persist.</p>
		<?
			}
			
			if (!count($warnings) && !count($errors)) {
		?>
		<p>Extension is ready to be installed. No problems found.</p>
		<?
			}
		?>
	</section>
	<? if (!count($errors)) { ?>
	<footer>
		<a href="<?=DEVELOPER_ROOT?>extensions/install/process/" class="button blue">Install</a>
	</footer>
	<? } ?>
</div>