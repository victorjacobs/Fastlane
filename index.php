<?php
	
	require_once "include/imdb.class.php";
	
	$foo = imdb::get_details("tt1045778");
	var_dump($foo);
	
	//imdb::flush_cache();
	

	
	// echo imdb::cache("Austin Powers in Goldmember", null, imdb::CACHE_TITLE_PAGE);

?>