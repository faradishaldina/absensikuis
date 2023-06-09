<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Permission;
use App\Models\Presence;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $attendances = Attendance::query()
            // ->with('positions')
            ->forCurrentUser(auth()->user()->position_id)
            ->get()
            ->sortByDesc('data.is_end')
            ->sortByDesc('data.is_start');

        return view('home.index', [
            "title" => "Homepage",
            "attendances" => $attendances
        ]);
    }

    public function show(Attendance $attendance)
    {
        $presences = Presence::query()
            ->where('attendance_id', $attendance->id)
            ->where('user_id', auth()->user()->id)
            ->get();

        $isHasEnterToday = $presences
            ->where('presence_date', now()->toDateString())
            ->isNotEmpty();

        $isTherePermission = Permission::query()
            ->where('permission_date', now()->toDateString())
            ->where('attendance_id', $attendance->id)
            ->where('user_id', auth()->user()->id)
            ->first();

        $data = [
            'is_has_enter_today' => $isHasEnterToday, // sudah absen masuk
            'is_not_out_yet' => $presences->where('presence_out_time', null)->isNotEmpty(), // belum absen pulang
            'is_there_permission' => (bool) $isTherePermission,
            'is_permission_accepted' => $isTherePermission?->is_accepted ?? false
        ];

        $holiday = $attendance->data->is_holiday_today ? Holiday::query()
            ->where('holiday_date', now()->toDateString())
            ->first() : false;

        $history = Presence::query()
            ->where('user_id', auth()->user()->id)
            ->where('attendance_id', $attendance->id)
            ->get();

        // untuku melihat karyawan yang tidak hadir
        $priodDate = CarbonPeriod::create($attendance->created_at->toDateString(), now()->toDateString())
            ->toArray();

        foreach ($priodDate as $i => $date) { // get only stringdate
            $priodDate[$i] = $date->toDateString();
        }

        $priodDate = array_slice(array_reverse($priodDate), 0, 30);

        return view('home.show', [
            "title" => "Attendance Information",
            "attendance" => $attendance,
            "data" => $data,
            "holiday" => $holiday,
            'history' => $history,
            'priodDate' => $priodDate
        ]);
    }

    public function permission(Attendance $attendance)
    {
        return view('home.permission', [
            "title" => "Permission Form",
            "attendance" => $attendance
        ]);
    }

    // for qrcode
    public function sendEnterPresenceUsingQRCode()
    {
        $code = request('code');
        $attendance = Attendance::query()->where('code', $code)->first();

        if ($attendance && $attendance->data->is_start && $attendance->data->is_using_qrcode) { // sama (harus) dengan view
            // fix: user bisa absensi dengan tanggal yang sama, cek apakah user id attendance id dan presence date sudah ada
            Presence::create([
                "user_id" => auth()->user()->id,
                "attendance_id" => $attendance->id,
                "presence_date" => now()->toDateString(),
                "presence_enter_time" => now()->toTimeString(),
                "presence_out_time" => null
            ]);

            return response()->json([
                "success" => true,
                "message" => "Prensence of '" . auth()->user()->name . "' successfully saved ."
            ]);
        }

        return response()->json([
            "success" => false,
            "message" => "Error while recording attendance."
        ], 400);
    }

    public function sendOutPresenceUsingQRCode()
    {
        $code = request('code');
        $attendance = Attendance::query()->where('code', $code)->first();

        if (!$attendance)
            return response()->json([
                "success" => false,
                "message" => "Error while recording attendance."
            ], 400);

        // jika absensi sudah jam pulang (is_end) dan tidak menggunakan qrcode (kebalikan)
        if (!$attendance->data->is_end && !$attendance->data->is_using_qrcode) // sama (harus) dengan view
            return false;

        $presence = Presence::query()
            ->where('user_id', auth()->user()->id)
            ->where('attendance_id', $attendance->id)
            ->where('presence_date', now()->toDateString())
            ->where('presence_out_time', null)
            ->first();

        if (!$presence) // hanya untuk sekedar keamanan (kemungkinan)
            return response()->json([
                "success" => false,
                "message" => "Error while recording attendance."
            ], 400);

        // untuk refresh if statement
        $this->data['is_not_out_yet'] = false;
        $presence->update(['presence_out_time' => now()->toTimeString()]);

        return response()->json([
            "success" => true,
            "message" => "Atas nama '" . auth()->user()->name . "' Home presence successfully recorded ."
        ]);
    }
}
