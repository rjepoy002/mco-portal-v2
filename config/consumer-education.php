<?php

const PALECO_CONSUMER_EDUCATION_PLAYLIST_ID = 'PLQWmQ9rNn-E8';

const PALECO_CONSUMER_EDUCATION_VIDEOS = [
    ['id' => '6VcVyVUIKPc', 'title' => 'PALECO Collection Partners'],
    ['id' => 'EAn10bwZwqc', 'title' => 'RA 11361'],
    ['id' => '3_z3b8qo7gg', 'title' => 'Safety Tips for Adults'],
    ['id' => '96lyKk8ZUvk', 'title' => 'AntiPilferage General'],
    ['id' => '8ze9SyZId4I', 'title' => 'Blackout'],
    ['id' => 'UIIGU-O0uvE', 'title' => 'Electrician Reminder'],
    ['id' => 'myzhFxS7Djw', 'title' => 'Magbayad ng Maaga'],
    ['id' => 'QXPqyp7pueo', 'title' => 'Notice of Disconnection Reminders'],
    ['id' => 'Dji4wcnbFJU', 'title' => 'Tipid Tips for Summer'],
    ['id' => 'hRx-hV4XoJM', 'title' => 'Change of Name'],
];

function consumerEducationPlaylistId(): string
{
    $playlistId = PALECO_CONSUMER_EDUCATION_PLAYLIST_ID;

    if (!preg_match('/^[A-Za-z0-9_-]+$/', $playlistId)) {
        throw new RuntimeException('Consumer Education playlist configuration is invalid.');
    }

    return $playlistId;
}

function consumerEducationVideos(): array
{
    foreach (PALECO_CONSUMER_EDUCATION_VIDEOS as $video) {
        $videoId = (string) ($video['id'] ?? '');
        $title = trim((string) ($video['title'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) || $title === '') {
            throw new RuntimeException('Consumer Education video configuration is invalid.');
        }
    }

    return PALECO_CONSUMER_EDUCATION_VIDEOS;
}

function consumerEducationPlaylistUrl(): string
{
    return 'https://www.youtube.com/playlist?list='
        . rawurlencode(consumerEducationPlaylistId());
}

function consumerEducationEmbedUrl(): string
{
    return 'https://www.youtube-nocookie.com/embed/videoseries?list='
        . rawurlencode(consumerEducationPlaylistId());
}

function consumerEducationVideoEmbedUrl(string $videoId): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        throw new InvalidArgumentException('Consumer Education video ID is invalid.');
    }

    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId)
        . '?rel=0';
}

function consumerEducationThumbnailUrl(string $videoId): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        throw new InvalidArgumentException('Consumer Education video ID is invalid.');
    }

    return 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/hqdefault.jpg';
}