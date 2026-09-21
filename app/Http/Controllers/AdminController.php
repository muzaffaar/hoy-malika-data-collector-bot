<?php

namespace App\Http\Controllers;

use App\Data\DateRange;
use App\Http\Requests\DashboardFilterRequest;
use App\Models\Participant;
use App\Models\VoiceRecording;
use App\Services\DashboardStatisticsService;
use App\Services\OriginalStorage;
use App\Services\ParticipantDeletion;
use App\Services\SystemHealth;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard(DashboardFilterRequest $request, DashboardStatisticsService $statistics)
    {
        $range = DateRange::fromFilters($request->validated());

        return view('admin.dashboard', ['overview' => $statistics->getOverview($range), 'charts' => $statistics->charts($range), 'range' => $range]);
    }

    public function participants(DashboardFilterRequest $request)
    {
        $filters = $request->validated();
        $range = DateRange::fromFilters($filters);
        $q = $range->apply(Participant::query())->withMin('recordings', 'telegram_received_at')->withMax('recordings', 'telegram_received_at');
        if ($s = $filters['search'] ?? null) {
            $q->where(fn ($q) => $q->where('first_name', 'like', '%'.$s.'%')->orWhere('telegram_username', 'like', '%'.$s.'%')->when(ctype_digit($s), fn ($q) => $q->orWhere('telegram_user_id', $s)));
        }
        if ($g = $filters['gender'] ?? null) {
            $q->where('gender', $g);
        }
        if ($a = $filters['age'] ?? null) {
            $q->where('age_range', $a);
        }
        if (isset($filters['min_recordings'])) {
            $q->where('recording_count', '>=', $filters['min_recordings']);
        }
        if (isset($filters['max_recordings'])) {
            $q->where('recording_count', '<=', $filters['max_recordings']);
        }

        return view('admin.participants', ['participants' => $q->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->paginate(25)->withQueryString(), 'range' => $range]);
    }

    public function participant(Participant $participant)
    {
        return view('admin.participant', ['participant' => $participant, 'recordings' => $participant->recordings()->latest()->paginate(20)]);
    }

    public function recordings(DashboardFilterRequest $request)
    {
        $filters = $request->validated();
        $range = DateRange::fromFilters($filters);
        $q = $range->apply(VoiceRecording::with('participant'), 'telegram_received_at');
        if ($s = $filters['search'] ?? null) {
            $q->where(fn ($q) => $q->where('stored_filename', 'like', '%'.$s.'%')->orWhere('sha256_checksum', 'like', '%'.$s.'%'));
        }
        if ($p = $filters['participant_id'] ?? null) {
            $q->where('participant_id', $p);
        }
        if ($g = $filters['gender'] ?? null) {
            $q->whereHas('participant', fn ($q) => $q->where('gender', $g));
        }
        if ($a = $filters['age'] ?? null) {
            $q->whereHas('participant', fn ($q) => $q->where('age_range', $a));
        }
        if ($s = $filters['sync'] ?? null) {
            $q->where(fn ($q) => $q->where('backup_status', $s)->orWhere('local_sync_status', $s));
        }

        return view('admin.recordings', ['recordings' => $q->latest('telegram_received_at')->paginate(25)->withQueryString(), 'range' => $range]);
    }

    public function recording(VoiceRecording $recording)
    {
        return view('admin.recording', compact('recording'));
    }

    public function download(VoiceRecording $recording, OriginalStorage $storage)
    {
        abort_if($recording->deletion_requested_at, 410);
        abort_unless($storage->valid($recording), 409, 'Original file is missing or corrupt');

        // libmagic reports Ogg as application/ogg on some builds; browsers only play it as audio/ogg.
        $mime = $recording->mime_type === 'application/ogg' ? 'audio/ogg' : $recording->mime_type;
        $playable = in_array($mime, ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/aac', 'audio/opus']);

        return response()->file($storage->path($recording->relative_storage_path), [
            'Content-Type' => $playable ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($playable ? 'inline' : 'attachment').'; filename="'.$recording->stored_filename.'"',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function deleteParticipant(Request $request, Participant $participant, ParticipantDeletion $deletion)
    {
        $request->validate(['confirmation' => ['required', 'in:DELETE '.$participant->id]]);
        $deletion->request($participant, $request->user()->id);

        return back()->with('status', 'Deletion requested. Copies remain tracked until the local agent and Drive confirm removal.');
    }

    public function system(SystemHealth $health)
    {
        return view('admin.system', ['checks' => $health->report()]);
    }
}
