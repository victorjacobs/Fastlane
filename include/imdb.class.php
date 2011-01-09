<?php
	
	/*
	 * NOTE on regexes: +? is ungreedy, which mean that php will try to find shortest match
	*/
	
	class imdb{
		// Settings
		const CACHE_ENABLED = true;
		const DEBUG = true;
		
		// Don't touch these
		// Cache for exact hits by imdb
		const CACHE_TITLE_PAGE = 1;
		// Cache for array with search results
		const CACHE_HITS = 2;
		// Cache (empty file) for no search results
		const CACHE_NO_MATCH = 3;
		// Cache for parsed details of movie
		const CACHE_DETAILS = 4;
		
		static public function get_details($title){
			self::debug_log("<b>imdb::get_details($title)</b> called");
			// Title can be an id or a title depending whether or not CACHE_TITLE_PAGE exists
			// This is because of an optimization made in function of imdb's behavior: when queried and imdb
			//  finds an exact match; there will be no results page, but we are taken directly to the title page
			//  imdb::lookup caches this. Making the entire operation faster by not doing double work!
			// NOTE: first if clause should be CACHE_DETAILS one
			if(self::cache_exists($title, self::CACHE_DETAILS)) {
				self::debug_log("CACHE_DETAILS found, returning unserialized data...");
				return self::cache($title, null, self::CACHE_DETAILS);
			}elseif(self::cache_exists($title, self::CACHE_TITLE_PAGE)){
				self::debug_log("CACHE_TITLE_PAGE found, extracting data...");
				$data_raw = self::cache($title, null, self::CACHE_TITLE_PAGE);
			}elseif(preg_match('$tt[0-9]{7}$s', $title)){
				self::debug_log("No cache found, fetching webpage from imdb...");
				// In this clause the $title MUST be an title ID
				// Don't cache this
				$data_raw = file_get_contents("http://www.imdb.com/title/$title/");
			}else{
				return false;
			}
			
			// Get title id from page
			preg_match('$tt[0-9]{7}$', $data_raw, $title_id);
			$title_id = $title_id[0];
			
			// Get rating
			preg_match('$[0-9]{1,2}\.{0,1}[0-9]{0,1}/10$s', $data_raw, $rating);
			$rating = $rating[0];
			
			// Get release date in both readable and unix epoch form
			preg_match('$<h5>Release Date:</h5>.+?<a$s', $data_raw, $release_date);
			$release_date['readable'] = trim(strip_tags(str_replace(array("<a", "Release Date:"), "", $release_date[0])));
			unset($release_date[0]);
			list($day, $month, $year, ) = explode(" ", $release_date['readable']);
			$release_date['unix'] = mktime(0, 0, 0, self::month_to_integer($month), $day, $year);
			
			// Get all genres
			preg_match_all('$<a href="/Sections/Genres/[a-zA-Z]+?/">[a-zA-Z]+?</a>$s', $data_raw, $genres);
			foreach($genres[0] as $genre){
				$genres_cleaned[] = strip_tags($genre);
			}
			$genres = $genres_cleaned;
			
			// Get movie poster
			preg_match('$name="poster" href="/rg/action-box-title/primary-photo/media/rm[0-9]+?/'. $title_id .'" title$s', $data_raw, $poster_url_raw);
			var_dump($poster_url_raw);
			
			// Get tagline
			preg_match('$<h5>Tagline:</h5>.+? <a$s', $data_raw, $tagline);
			$tagline = trim(strip_tags(str_replace(array("<h5>Tagline:</h5>", "<a"), "", $tagline[0])));
			
			// Get plot summary, this involves fetching another page. We don't cache this page, only results
			$data_raw_plot = file_get_contents("http://www.imdb.com/title/$title_id/plotsummary");
			preg_match('$<p class="plotpar">.+?<i>$s', $data_raw_plot, $plot_raw);
			$plot = trim(strip_tags($plot_raw[0]));
			
			// Store data somewhere nice
			$data = new stdClass;
			$data->title_id = $title_id;
			$data->rating = $rating;
			$data->release_date = new stdClass;
			$data->release_date->readable = $release_date['readable'];
			$data->release_date->unix = $release_date['unix'];
			$data->genre = $genres;
			$data->tagline = $tagline;
			$data->plot = $plot;
			
			self::cache($title, $data, self::CACHE_DETAILS);
			
			return $data;
		}
		
		
		/*	
			imdb::lookup($search_string)
			
			Preforms a search on imdb web frontend, filters useful information and returns titles that match the 
		 	search query (in an assoc array "id" => "title"). Important to note it that results are indefinitely
		 	cached for now to avoid querying imdb's relatively slow web servers. If the search query gets a
			direct hit, then imdb::lookup returns true and caches the fetched webpage for later processing in
		 	imdb::get_info()
			
			NOTES:
			 - imdb::lookup() only returns movies and ignores tv series and videogames that are also in the imdb
				database
			 - Results are sorted as follows: popular hits then exact hits, both sorted by release year
				>> From a coding point of view this doesn't really matter, since the array is assoc, but when
					looping through the array with foreach, the order of the elements matters
		*/
		static public function lookup($search_string){
			self::debug_log("<b>imdb::lookup(\"$search_string\")</b> called");
			self::debug_log("searching for $search_string");
			
			// If there are cache hits for CACHE_TITLE_PAGE or CACHE_HITS, we can skip the fetching from imdb's slow servers
			if(self::cache_exists($search_string, self::CACHE_NO_MATCH)){
				self::debug_log("CACHE_NO_MATCH found, imdb::lookup() returning false");
				return false;
			}
			if(self::cache_exists($search_string, self::CACHE_TITLE_PAGE)){
				self::debug_log("CACHE_TITLE_PAGE found, imdb::lookup() returning true");
				return true;
			}
			if(self::cache_exists($search_string, self::CACHE_HITS)){
				self::debug_log("CACHE_HITS found, imdb::lookup() returning hits from cache");
				return self::cache($search_string, null, self::CACHE_HITS);
			}
			
			self::debug_log("fetching data from http://www.imdb.com/find?s=tt&q=". urlencode($search_string));
			$data_raw = file_get_contents("http://www.imdb.com/find?s=tt&q=". urlencode($search_string));
			
			if(preg_match('$No Matches.$', $data_raw) != 0){
				self::debug_log("no matches, imdb::lookup() returning false");
				self::cache($search_string, "", self::CACHE_NO_MATCH);
				return false;
			}
			
			// Include both in lookups, e.g. gone in 60 seconds
			// NOTE on regexes: () are special chars, so escape them properly
			$num_exact_slices = preg_match('$<p><b>Titles \(Exact Matches\)</b>.+?</table> </p>$s', $data_raw, $exact_slice_array);
			$num_popular_slices = preg_match('$<p><b>Popular Titles</b>.+?</table> </p>$s', $data_raw, $popular_slice_array);
			
			// Direct hit, which means we $data_raw contains /title/tt0242423/ html, we'll cache it for later use
			if($num_exact_slices == 0 && $num_popular_slices == 0){
				self::debug_log("direct hit for $search_string");
				self::cache($search_string, $data_raw, self::CACHE_TITLE_PAGE);
				return true;
			}else{
				// Now find all the hits in the slice (structured in <tr></tr> rows)
				$num_exact_hits = preg_match_all('$<tr>.+?</tr>$s', $exact_slice_array[0], $exact_hits_array_raw);
				$num_popular_hits = preg_match_all('$<tr>.+?</tr>$s', $popular_slice_array[0], $popular_hits_array_raw);
				
				$num_hits = $num_exact_hits + $num_popular_hits;
				self::debug_log("imdb::lookup() for $search_string returned $num_hits results ($num_exact_hits exact hits and $num_popular_hits popular hits)");
				
				// SORTING
				// array_merge() on both slices since they are both tables, popular hits first
				$hits = array_merge($popular_hits_array_raw[0], $exact_hits_array_raw[0]);
				
				// We process both exact and popular hits in the same loop, but afterwards we split them again so we
				//  can uasort them seperately. Afterwards we array_merge again with the popular hits on top,
				//  followed by the exact matches.
				$loop = 1;
				$popular_hits_array_structured = array();
				$exact_hits_array_structured = array();
				foreach($hits as $hit){
					// Skip rows when it seems like a tv serie or video game, cause we don't want those
					if(preg_match('$\(VG\)$', $hit) == 0 && preg_match('$TV$', $hit) == 0 && preg_match('$\(V\)$', $hit) == 0){
						preg_match('$/title/tt[0-9]{7}$', $hit, $movie_id);
						preg_match_all('$<a href="'. $movie_id[0] .'/" [^>]*>.+?</a>$s', $hit, $movie_name);
						if(preg_match('$\([1-2]{1}[0-9]{3}\)$', $hit, $movie_year) == 0){
							// Try something else since, for some reason, imdb sometimes returns years like
							//  (1998/I)
							preg_match('$\([1-2]{1}[0-9]{3}/{1}[A-Z]{1,3}\)$', $hit, $movie_year);
							$temp = explode("/", $movie_year[0]);
							$movie_year[0] = $temp[0] . ")";
							unset($temp);
						}

						if($loop <= $num_popular_hits){
							$hits_array_structured = &$popular_hits_array_structured;
						}else{
							$hits_array_structured = &$exact_hits_array_structured;
						}
						
						// By calling $movie_name[0][1] we assume that the movie title is in the last link
						//  found by preg_match_all, we don't check this
						$hits_array_structured[str_replace("/title/", "", $movie_id[0])] =
							strip_tags(end($movie_name[0])) . " " . $movie_year[0];
					}
					// Do $loop++ after the if clause, cause otherwise we don't take the tv series and VGs into account
					$loop++;
				}
				
				unset($hits_array_structured);
				
				// If $hits_array_structured contains more than one hit, sort them by year
				// NOTE: sort them by levenshtein() instead of alphabetical?
				$array_sort = create_function('$a, $b', '
					(int)$year_a = str_replace(array("(", ")"), "", substr($a, strlen($a) - 6, 6));
					(int)$year_b = str_replace(array("(", ")"), "", substr($b, strlen($b) - 6, 6));
					
					if($year_a == $year_b){
						$title_a = str_replace("(". $year_a .")", "", $a);
						$title_b = str_replace("(". $year_b .")", "", $b);
						return strcmp($title_a, $title_b);
					}
					
					return ($year_a < $year_b) ? 1 : -1;
				');
				
				if(count($popular_hits_array_structured) != 1){
					uasort($popular_hits_array_structured, $array_sort);
				}
				if(count($exact_hits_array_structured) != 1){
					uasort($exact_hits_array_structured, $array_sort);
				}
				
				$hits_array_structured = array_merge($popular_hits_array_structured, $exact_hits_array_structured);
				
				self::cache($search_string, $hits_array_structured, self::CACHE_HITS);
				
				self::debug_log($hits_array_structured);
				
				return $hits_array_structured;
			}
		}
		
		static public function cache_exists($id, $what){
			if(self::CACHE_ENABLED){
				return file_exists("cache/". self::cache_prepare_id($id, $what) .".gz");
			}else{
				// Always pretend that the cache doesn't exist
				self::debug_log("cache may exist, but is disabled");
				return false;
			}
		}
		
		static private function cache_prepare_id($id, $what){
			switch($what){
				case self::CACHE_TITLE_PAGE:
					return md5($id . ".title");
				break;
				
				case self::CACHE_HITS:
					return md5($id . ".hits");
				break;
				
				case self::CACHE_NO_MATCH:
					return md5($id . ".miss");
				break;
				
				case self::CACHE_DETAILS:
					return md5($id . ".details");
				break;
			}
		}
		
		static public function cache($id, $data = null, $what){
			$cache_id = self::cache_prepare_id($id, $what);
			
			// Note that is_null("") == false
			if(is_null($data)){
				// Read data
				if(!self::CACHE_ENABLED) return false;
				if(!self::cache_exists($id, $what)) return false;
				
				self::debug_log("Reading from cache/$cache_id.gz");
				
				$handle = gzopen("cache/$cache_id.gz", "r");
				while(!feof($handle)){
					$output .= gzread($handle, 8192);
				}
				gzclose($handle);
				
				if($what == self::CACHE_HITS || $what == self::CACHE_DETAILS) $output = unserialize($output);
				return $output;
			}else{
				// Write data, we assume that imdb data is static
				if(!self::CACHE_ENABLED) return false;
				if(self::cache_exists($id, $what)) return true;
				
				if($what == self::CACHE_HITS || $what == self::CACHE_DETAILS) $data = serialize($data);
				
				self::debug_log("writing cache to cache/$cache_id.gz");
				
				$handle = gzopen("cache/$cache_id.gz", "w");
				gzwrite($handle, $data);
				gzclose($handle);
				return true;
			}
		}
		
		static private function debug_log($data, $include_pre_tags = false){
			if(self::DEBUG){
				if(is_string($data)){
					$string = $data;
					
					if(!$include_pre_tags){
						// If we get html, we shouldn't format the links, we assume that the html
						//  is valid ofcourse,
						//  that means there should be a DOCTYPE decleration of <html> in the top of the document.
						//  This code will attempt to find that by reading the first few lines.
						$subject = trim(substr($string, 0, 200));
						if(substr_count($subject, "<html>") == 0 && substr_count($subject, "DOCTYPE") == 0){
							$words = explode(" ", $string);
							// We assume we don't use very long debug log messages cause otherwise this
							//  can get expensive
							foreach($words as $word){
								if(substr_count($word, "http://") != 0 || substr_count($word, "www.") != 0){
									$string = str_replace($word, '<a href="'. $word .'" target="_blank">'.
										$word .'</a>', $string);
								}
							}
						}
					}else{
						$string = "<pre>". htmlentities($string) ."</pre>";
					}
					
					echo $string . "<br />";
				}else{
					var_dump($data);
				}
				
			}
		}
		
		static public function flush_cache() {
			if(self::DEBUG){
				self::debug_log("Flushing cache");
				$cache_dir = opendir("cache/");
				while(false !== ($file = readdir($cache_dir))){
					if($file != "." && $file != "..") {
						unlink("cache/$file");
					}
				}
			}
		}
		
		static private function month_to_integer($month){
			$conversion = array(
				"january" 	=> 1,
				"february" 	=> 2,
				"march" 	=> 3,
				"april" 	=> 4,
				"may" 		=> 5,
				"june" 		=> 6,
				"july" 		=> 7,
				"august" 	=> 8,
				"september" => 9,
				"october"	=> 10,
				"november"	=> 11,
				"december"	=> 12
			);
			return $conversion[strtolower($month)];
		}
	}

?>