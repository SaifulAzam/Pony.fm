<?php

/**
 * Pony.fm - A community for pony fan music.
 * Copyright (C) 2015 Peter Deltchev
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Poniverse\Ponyfm\Models;

use Exception;
use Helpers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Auth;
use Cache;
use Poniverse\Ponyfm\Exceptions\TrackFileNotFoundException;
use Poniverse\Ponyfm\Traits\TrackCollection;
use Poniverse\Ponyfm\Traits\SlugTrait;
use Venturecraft\Revisionable\RevisionableTrait;

class Album extends Model
{
    use SoftDeletes, SlugTrait, DispatchesJobs, TrackCollection, RevisionableTrait;

    protected $dates = ['deleted_at'];
    protected $fillable = ['user_id', 'title', 'slug'];

    public static function summary()
    {
        return self::select('id', 'title', 'user_id', 'slug', 'created_at', 'cover_id', 'comment_count',
            'download_count', 'view_count', 'favourite_count');
    }

    public function scopeUserDetails($query)
    {
        if (Auth::check()) {
            $query->with([
                'users' => function ($query) {
                    $query->whereUserId(Auth::user()->id);
                }
            ]);
        }

        return !$query;
    }

    protected $table = 'albums';

    public function user()
    {
        return $this->belongsTo('Poniverse\Ponyfm\Models\User');
    }

    public function users()
    {
        return $this->hasMany('Poniverse\Ponyfm\Models\ResourceUser');
    }

    public function favourites()
    {
        return $this->hasMany('Poniverse\Ponyfm\Models\Favourite');
    }

    public function cover()
    {
        return $this->belongsTo('Poniverse\Ponyfm\Models\Image');
    }

    public function tracks()
    {
        return $this->hasMany('Poniverse\Ponyfm\Models\Track')->orderBy('track_number', 'asc');
    }

    public function trackFiles() {
        return $this->hasManyThrough(TrackFile::class, Track::class, 'album_id', 'track_id');
    }

    public function comments()
    {
        return $this->hasMany('Poniverse\Ponyfm\Models\Comment')->orderBy('created_at', 'desc');
    }

    public static function mapPublicAlbumShow(Album $album)
    {
        $tracks = [];
        foreach ($album->tracks as $track) {
            $tracks[] = Track::mapPublicTrackSummary($track);
        }

        $formats = [];
        foreach (Track::$Formats as $name => $format) {
            $formats[] = [
                'name' => $name,
                'extension' => $format['extension'],
                'url' => $album->getDownloadUrl($name),
                'size' => Helpers::formatBytes($album->getFilesize($name)),
                'isCacheable' => (in_array($name, Track::$CacheableFormats) ? true : false)
            ];
        }

        $comments = [];
        foreach ($album->comments as $comment) {
            $comments[] = Comment::mapPublic($comment);
        }

        $is_downloadable = 0;
        foreach ($album->tracks as $track) {
            if ($track->is_downloadable == 1) {
                $is_downloadable = 1;
                break;
            }
        }

        $data = self::mapPublicAlbumSummary($album);
        $data['tracks'] = $tracks;
        $data['comments'] = $comments;
        $data['formats'] = $formats;
        $data['description'] = $album->description;
        $data['is_downloadable'] = $is_downloadable;
        $data['share'] = [
            'url' => action('AlbumsController@getShortlink', ['id' => $album->id]),
            'tumblrUrl' => 'http://www.tumblr.com/share/link?url=' . urlencode($album->url) . '&name=' . urlencode($album->title) . '&description=' . urlencode($album->description),
            'twitterUrl' => 'https://platform.twitter.com/widgets/tweet_button.html?text=' . $album->title . ' by ' . $album->user->display_name . ' on Pony.fm'
        ];

        return $data;
    }

    public static function mapPublicAlbumSummary(Album $album)
    {
        $userData = [
            'stats' => [
                'views' => 0,
                'downloads' => 0
            ],
            'is_favourited' => false
        ];

        if (Auth::check() && $album->users->count()) {
            $userRow = $album->users[0];
            $userData = [
                'stats' => [
                    'views' => (int)$userRow->view_count,
                    'downloads' => (int)$userRow->download_count,
                ],
                'is_favourited' => (bool)$userRow->is_favourited
            ];
        }

        return [
            'id' => (int)$album->id,
            'track_count' => (int)$album->track_count,
            'title' => $album->title,
            'slug' => $album->slug,
            'created_at' => $album->created_at->format('c'),
            'stats' => [
                'views' => (int)$album->view_count,
                'downloads' => (int)$album->download_count,
                'comments' => (int)$album->comment_count,
                'favourites' => (int)$album->favourite_count
            ],
            'covers' => [
                'small' => $album->getCoverUrl(Image::SMALL),
                'normal' => $album->getCoverUrl(Image::NORMAL),
                'original' => $album->getCoverUrl(Image::ORIGINAL)
            ],
            'url' => $album->url,
            'user' => [
                'id' => (int)$album->user->id,
                'name' => $album->user->display_name,
                'url' => $album->user->url,
            ],
            'user_data' => $userData,
            'permissions' => [
                'delete' => Auth::check() && Auth::user()->id == $album->user_id,
                'edit' => Auth::check() && Auth::user()->id == $album->user_id
            ]
        ];
    }

    public function hasCover()
    {
        return $this->cover_id != null;
    }

    public function getUrlAttribute()
    {
        return action('AlbumsController@getShow', ['id' => $this->id, 'slug' => $this->slug]);
    }

    public function getDownloadUrl($format)
    {
        return action('AlbumsController@getDownload', ['id' => $this->id, 'extension' => Track::$Formats[$format]['extension']]);
    }

    public function getFilesize($format)
    {
        $tracks = $this->tracks;
        if (!count($tracks)) {
            return 0;
        }

        return Cache::remember($this->getCacheKey('filesize-' . $format), 1440, function () use ($tracks, $format) {
            $size = 0;

            foreach ($tracks as $track) {
                /** @var $track Track */

                // Ensure that only downloadable tracks are added onto the file size
                if ($track->is_downloadable == 1) {
                    try {
                        $size += $track->getFilesize($format);

                    } catch (TrackFileNotFoundException $e) {
                        // do nothing - this track won't be included in the download
                    }
                }
            }

            return $size;
        });
    }

    public function getCoverUrl($type = Image::NORMAL)
    {
        if (!$this->hasCover()) {
            return $this->user->getAvatarUrl($type);
        }

        return $this->cover->getUrl($type);
    }

    public function getDirectory()
    {
        $dir = (string)(floor($this->id / 100) * 100);

        return \Config::get('ponyfm.files_directory') . '/tracks/' . $dir;
    }

    public function getDates()
    {
        return ['created_at', 'deleted_at', 'published_at'];
    }

    public function getFilenameFor($format)
    {
        if (!isset(Track::$Formats[$format])) {
            throw new Exception("$format is not a valid format!");
        }

        $format = Track::$Formats[$format];

        return "{$this->id}.{$format['extension']}.zip";
    }

    public function updateTrackNumbers()
    {
        $tracks = Track::whereAlbumId($this->id)->get();
        $index = 1;

        foreach ($tracks as $track) {
            /** @var $track Track */

            $track->track_number = $index;
            $index++;
            $track->updateTags();
            $track->save();
        }
    }

    public function syncTrackIds($trackIds)
    {
        $trackIdsInAlbum = [];
        foreach ($this->tracks as $track) {
            $trackIdsInAlbum[] = $track->id;
        }

        $trackIdsCount = count($trackIds);
        $trackIdsInAlbumCount = count($trackIdsInAlbum);
        $isSame = true;

        if ($trackIdsInAlbumCount != $trackIdsCount) {
            $isSame = false;
        } else {
            for ($i = 0; $i < $trackIdsInAlbumCount; $i++) {
                if ($i >= $trackIdsCount || $trackIdsInAlbum[$i] != $trackIds[$i]) {
                    $isSame = false;
                    break;
                }
            }
        }

        if ($isSame) {
            return;
        }

        $index = 1;
        $tracksToRemove = [];
        $albumsToFix = [];

        foreach ($this->tracks as $track) {
            $tracksToRemove[$track->id] = $track;
        }

        foreach ($trackIds as $trackId) {
            if (!strlen(trim($trackId))) {
                continue;
            }

            /** @var $track Track */
            $track = Track::find($trackId);

            if ($track->album_id != null && $track->album_id != $this->id) {
                $albumsToFix[] = $track->album;
            }

            $track->album_id = $this->id;
            $track->track_number = $index;
            $track->updateTags();
            $track->save();

            unset($tracksToRemove[$track->id]);
            $index++;
        }

        foreach ($tracksToRemove as $track) {
            /** @var $track Track */

            $track->album_id = null;
            $track->track_number = null;
            $track->updateTags();
            $track->save();
        }

        foreach ($albumsToFix as $album) {
            /** @var $album Album */

            $album->updateTrackNumbers();
        }

        foreach (Track::$Formats as $name => $format) {
            Cache::forget($this->getCacheKey('filesize' . $name));
        }
    }

    public function getCacheKey($key)
    {
        return 'album-' . $this->id . '-' . $key;
    }
}