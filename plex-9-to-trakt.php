<?php

// This script adds your TV shows & movies from Plex 9 to Trakt, including seen/unseen status.

define('PLEX_URL', 'http://<IP of your Plex 9 media server>:32400');
define('TRAKT_APIKEY', '<your Trakt.tv API key>');
define('TRAKT_USERNAME', '<your Trakt.tv username>');
define('TRAKT_PASSWORD', '<your Trakt.tv password>');
define('TVSHOWS_SECTIONS', '2'); // Coma-separated value; you can see your sections here: http://<IP of your Plex 9 media server>:32400/library/sections
define('MOVIES_SECTIONS', '11'); // Coma-separated value; you can see your sections here: http://<IP of your Plex 9 media server>:32400/library/sections

// DO NOT MODIFY ANYTHING BELOW THIS LINE.

echo("\n\n=== Starting the import. This may take some time. ===\n\n");

ini_set('memory_limit', '512M');
set_time_limit(6000);

$movies = array();
foreach (explode(',', MOVIES_SECTIONS) as $movie_section) {
  echo("=== Looking for Movies in section $movie_section Please wait... ===\n\n");

  // Get XML with movies from Plex.
  $xml = simplexml_load_string(file_get_contents(PLEX_URL . "/library/sections/$movie_section/all"));
  
  foreach ($xml->Video AS $movie)
  {
    if ((string) $movie->attributes()->type == 'movie')
    {
      $title = (string) $movie->attributes()->title;
      $movies[$title]->year = (string) $movie->attributes()->year;
  		if ($movie->attributes()->viewCount)
			{
	      $movies[$title]->seen = true;
				$movies[$title]->lastPlayed = (string) $movie->attributes()->updatedAt;
			}
			else
			{
				$movies[$title]->seen = false;
			}
    }
  }
}
echo("=== Found " . count($movies) ." movies, adding them to Trakt now. ===\n\n");

$movies_watched = array();
$movies_unwatched = array();
foreach ($movies AS $title => $value)
{
  echo("Adding movie {$title}\n");
	unset($movie);
	$movie->title = (string) $title;
	$movie->year = (int) $value->year;
	if ($value->seen) {
		$movie->plays = 1;
		$movie->lastPlayed = (int) $value->lastPlayed;
		$movies_watched[] = $movie;
	} else {
		$movies_unwatched[] = $movie;
	}
}
add_movies_watched($movies_watched);
add_movies_unwatched($movies_unwatched);

function add_movies_watched($movies)
{
  $data->movies = $movies;
  echo curl_post('http://api.trakt.tv/movie/seen/', $data) . "\n";
}

function add_movies_unwatched($movies)
{
  $data->movies = $movies;
  echo curl_post('http://api.trakt.tv/movie/library/', $data) . "\n";
}

$shows = array();
foreach (explode(',', TVSHOWS_SECTIONS) as $tvshow_section) {
  echo("=== Looking for TV Shows in section $tvshow_section. Please wait... ===\n\n");
  
  // Get XML with shows from Plex.
  $xml = simplexml_load_string(file_get_contents(PLEX_URL . "/library/sections/$tvshow_section/all"));

  // Loop through shows and store keys in array.
  $show_keys = array();
  foreach ($xml->Directory AS $value)
  {
    $show_keys[] = (string) $value->attributes()->key;
  }

  // Loop through keys and get seasons.
  foreach ($show_keys AS $show_key)
  {
    $show_xml = simplexml_load_string(file_get_contents(PLEX_URL . $show_key));

    foreach ($show_xml->Directory AS $season)
    {
      if ((string) $season->attributes()->type == 'season')
      {
        $title = (string) $show_xml->attributes()->parentTitle;

        if (!isset($shows[$title]))
        {
          $shows[$title]->year = (integer) $show_xml->attributes()->parentYear;
          $shows[$title]->seasons = array();
        }

        $season_key = (string) $season->attributes()->key;
        $season_no = (string) $season->attributes()->index;
        $shows[$title]->seasons[$season_no] = array();

        // Get the episodes for this season.
        $episodes_xml = simplexml_load_string(file_get_contents(PLEX_URL . $season_key));

        foreach ($episodes_xml->Video AS $episode)
        {
          if ((string) $episode->attributes()->type == 'episode')
          {
            $shows[$title]->seasons[$season_no][(integer) $episode->attributes()->index] = isset($episode->attributes()->viewCount) ? true : false;
          }
        }
      }
    }
  }
}

// So now we have all shows with episodes, start year and watch status. Add them to Trakt (as library items if unwatched, otherwise as seen)!
echo("=== Found " . count($shows) ." TV shows, adding them to Trakt now. ===\n\n");

foreach ($shows AS $title => $value)
{
  echo("Adding show {$title}\n");

  $data_watched = array();
  $data_unwatched = array();

  foreach ($value->seasons AS $season => $episodes)
  {
    foreach ($episodes AS $episode => $watched)
    {
      $ep->season = (int) $season;
      $ep->episode = (int) $episode;

      if ($watched)
      {
        $data_watched[] = $ep;
      }
      else
      {
        $data_unwatched[] = $ep;
      }

      unset($ep);
    }
  }

  if (count($data_watched) > 0)
  {
    $data->title = (string) $title;
    $data->year = (int) $value->year;
    $data->episodes = $data_watched;

    echo "Adding watched TV shows:\n";
    add_show_watched($data);

    unset($data);
  }

  if (count($data_unwatched) > 0)
  {
    $data->title = $title;
    $data->year = $value->year;
    $data->episodes = $data_unwatched;

    echo "Adding unwatched TV shows:\n";
    add_show_unwatched($data);

    unset($data);
  }
}

// Some function for recurring actions.
function add_show_watched($data)
{
  echo curl_post('http://api.trakt.tv/show/episode/seen/', $data) . "\n";
}

function add_show_unwatched($data)
{
  echo curl_post('http://api.trakt.tv/show/episode/library/', $data) . "\n";
}

function curl_post($url, $data)
{
  set_time_limit(30);
  
  $data->username = TRAKT_USERNAME;
  $data->password = sha1(TRAKT_PASSWORD);
  $data = json_encode($data);

  echo "----------\n";
  echo "POST $url".TRAKT_APIKEY."\n\n$data\n";
  echo "----------\n";

  $ch = curl_init();
  curl_setopt_array($ch, array(
    CURLOPT_URL => $url . TRAKT_APIKEY,
    CURLOPT_POSTFIELDS => $data,
      CURLOPT_POST => 1,
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_TIMEOUT => 0
    )
  );

  $return = curl_exec($ch);
  curl_close($ch);

  return $return;
}

?>