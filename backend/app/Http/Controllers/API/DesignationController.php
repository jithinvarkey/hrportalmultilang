<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\Designation;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DesignationController extends Controller {

    public function index() {
        return response()->json(Designation::with('department')->orderBy('title')->get());
    }

    public function store(Request $request) {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'title_ar'      => 'nullable|string|max:150',
            'level'         => 'nullable|in:junior,mid,senior,lead,manager,director,executive,management,staff',
            'department_id' => 'nullable|exists:departments,id',
            'min_salary'    => 'nullable|numeric|min:0',
            'max_salary'    => 'nullable|numeric|min:0|gte:min_salary',
            'is_active'     => 'boolean',
        ]);
        if (empty($data['department_id']))  $data['department_id'] = null;
        if (($data['min_salary'] ?? null) === '') $data['min_salary'] = null;
        if (($data['max_salary'] ?? null) === '') $data['max_salary'] = null;
        return response()->json(['designation' => Designation::create($data)], 201);
    }

    public function show($id) {
        return response()->json(['designation' => Designation::with('department')->findOrFail($id)]);
    }

    public function update(Request $request, $id) {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }
        $d = Designation::findOrFail($id);
        $data = $request->validate([
            'title'         => 'sometimes|required|string|max:150',
            'title_ar'      => 'nullable|string|max:150',
            'level'         => 'nullable|in:junior,mid,senior,lead,manager,director,executive,management,staff',
            'department_id' => 'nullable|exists:departments,id',
            'min_salary'    => 'nullable|numeric|min:0',
            'max_salary'    => 'nullable|numeric|min:0',
            'is_active'     => 'boolean',
        ]);
        if (array_key_exists('department_id', $data) && empty($data['department_id'])) $data['department_id'] = null;
        if (isset($data['min_salary']) && $data['min_salary'] === '') $data['min_salary'] = null;
        if (isset($data['max_salary']) && $data['max_salary'] === '') $data['max_salary'] = null;
        $d->update($data);
        return response()->json(['designation' => $d->fresh('department')]);
    }

    public function destroy($id) {
        if (!$this->canManage()) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }
        Designation::findOrFail($id)->delete();
        return response()->json(['message' => __('Position deleted')]);
    }

    /** Master data is maintained by HR / system admins only. */
    private function canManage(): bool
    {
        return auth()->check() && DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->where('model_has_roles.model_type', get_class(auth()->user()))
            ->whereIn('roles.name', ['super_admin', 'ceo', 'hr_manager', 'hr_staff'])
            ->exists();
    }
}
