@extends('layouts.poll')
@section('title', $poll->title)
@section('content')
    <livewire:calendar-poll.vote :poll-id="$poll->id" />
@endsection
