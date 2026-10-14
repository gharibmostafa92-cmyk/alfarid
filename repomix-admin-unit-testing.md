This file is a merged representation of a subset of the codebase, containing specifically included files, combined into a single document by Repomix.
The content has been processed where line numbers have been added, content has been formatted for parsing in markdown style.

# File Summary

## Purpose
This file contains a packed representation of a subset of the repository's contents that is considered the most important context.
It is designed to be easily consumable by AI systems for analysis, code review,
or other automated processes.

## File Format
The content is organized as follows:
1. This summary section
2. Repository information
3. Directory structure
4. Repository files (if enabled)
5. Multiple file entries, each consisting of:
  a. A header with the file path (## File: path/to/file)
  b. The full contents of the file in a code block

## Usage Guidelines
- This file should be treated as read-only. Any changes should be made to the
  original repository files, not this packed version.
- When processing this file, use the file path to distinguish
  between different files in the repository.
- Be aware that this file may contain sensitive information. Handle it with
  the same level of security as you would the original repository.
- Pay special attention to the Repository Description. These contain important context and guidelines specific to this project.

## Notes
- Some files may have been excluded based on .gitignore rules and Repomix's configuration
- Binary files are not included in this packed representation. Please refer to the Repository Structure section for a complete list of file paths, including binary files
- Only files matching these patterns are included: routes/api/admin.php, bootstrap/app.php, app/Http/Controllers/Controller.php, app/Http/Controllers/Api/V1/Admin/AuthController.php, app/Http/Controllers/Api/V1/Admin/BranchController.php, app/Http/Controllers/Api/V1/Admin/LocationController.php, app/Http/Controllers/Api/V1/Admin/UserController.php, app/Http/Controllers/Api/V1/Admin/RoleController.php, app/Http/Controllers/Api/V1/Admin/PermissionController.php, app/Http/Controllers/Api/V1/Admin/SettingController.php, app/Http/Requests/Api/V1/Admin/AdminLoginRequest.php, app/Http/Requests/Api/V1/Admin/StoreBranchRequest.php, app/Http/Requests/Api/V1/Admin/UpdateBranchRequest.php, app/Http/Requests/Api/V1/Admin/StoreLocationRequest.php, app/Http/Requests/Api/V1/Admin/UpdateLocationRequest.php, app/Http/Requests/Api/V1/Admin/StoreUserRequest.php, app/Http/Requests/Api/V1/Admin/UpdateUserRequest.php, app/Http/Requests/Api/V1/Admin/StoreRoleRequest.php, app/Http/Requests/Api/V1/Admin/UpdateRoleRequest.php, app/Http/Requests/Api/V1/Admin/UpdateSettingsRequest.php, app/Http/Resources/Api/V1/Admin/BranchResource.php, app/Http/Resources/Api/V1/Admin/LocationResource.php, app/Http/Resources/Api/V1/Admin/RoleResource.php, app/Http/Resources/Api/V1/Admin/UserResource.php, app/Models/User.php, app/Models/Branch.php, app/Models/Location.php, app/Models/Setting.php, app/Enums/StaffRole.php, app/Enums/BranchType.php, app/Enums/LocationType.php, app/Http/Middleware/EnsureStaffAuthenticated.php, app/Services/SettingService.php, config/auth.php, config/permission.php, config/sanctum.php, phpunit.xml, database/migrations/0001_01_01_000000_create_users_table.php, database/migrations/2026_10_14_224514_create_permission_tables.php, database/migrations/2026_10_14_224514_create_personal_access_tokens_table.php, database/migrations/2026_10_14_225722_create_branches_table.php, database/migrations/2026_10_14_230814_add_storage_details_to_branches_and_create_locations_table.php, database/migrations/2026_10_15_014600_add_staff_authentication_fields_to_users_table.php, database/migrations/2026_10_15_021000_create_settings_table.php, database/factories/UserFactory.php, database/factories/BranchFactory.php, database/factories/LocationFactory.php, database/seeders/DatabaseSeeder.php, database/seeders/RolePermissionSeeder.php, database/seeders/BranchSeeder.php, database/seeders/LocationSeeder.php, database/seeders/SettingSeeder.php, tests/TestCase.php, tests/Feature/AdminAuthEndpointsTest.php, tests/Feature/BranchEndpointsTest.php, tests/Feature/LocationEndpointsTest.php, tests/Feature/AdminUserEndpointsTest.php, tests/Feature/RolePermissionEndpointsTest.php, tests/Feature/AdminSettingsEndpointsTest.php, tests/Feature/AdminRouteAggregationTest.php
- Files matching patterns in .gitignore are excluded
- Files matching default ignore patterns are excluded
- Line numbers have been added to the beginning of each line
- Content has been formatted for parsing in markdown style
- Files are sorted by Git change count (files with more changes are at the bottom)

# User Provided Header
Admin Endpoints & Unit Testing Context (bags-platform backend)

# Directory Structure
```
app/
  Enums/
    BranchType.php
    LocationType.php
    StaffRole.php
  Http/
    Controllers/
      Api/
        V1/
          Admin/
            AuthController.php
            BranchController.php
            LocationController.php
            PermissionController.php
            RoleController.php
            SettingController.php
            UserController.php
      Controller.php
    Middleware/
      EnsureStaffAuthenticated.php
    Requests/
      Api/
        V1/
          Admin/
            AdminLoginRequest.php
            StoreBranchRequest.php
            StoreLocationRequest.php
            StoreRoleRequest.php
            StoreUserRequest.php
            UpdateBranchRequest.php
            UpdateLocationRequest.php
            UpdateRoleRequest.php
            UpdateSettingsRequest.php
            UpdateUserRequest.php
    Resources/
      Api/
        V1/
          Admin/
            BranchResource.php
            LocationResource.php
            RoleResource.php
            UserResource.php
  Models/
    Branch.php
    Location.php
    Setting.php
    User.php
  Services/
    SettingService.php
bootstrap/
  app.php
config/
  auth.php
  permission.php
  sanctum.php
database/
  factories/
    BranchFactory.php
    LocationFactory.php
    UserFactory.php
  migrations/
    0001_01_01_000000_create_users_table.php
    2026_10_14_224514_create_permission_tables.php
    2026_10_14_224514_create_personal_access_tokens_table.php
    2026_10_14_225722_create_branches_table.php
    2026_10_14_230814_add_storage_details_to_branches_and_create_locations_table.php
    2026_10_15_014600_add_staff_authentication_fields_to_users_table.php
    2026_10_15_021000_create_settings_table.php
  seeders/
    BranchSeeder.php
    DatabaseSeeder.php
    LocationSeeder.php
    RolePermissionSeeder.php
    SettingSeeder.php
routes/
  api/
    admin.php
tests/
  Feature/
    AdminAuthEndpointsTest.php
    AdminRouteAggregationTest.php
    AdminSettingsEndpointsTest.php
    AdminUserEndpointsTest.php
    BranchEndpointsTest.php
    LocationEndpointsTest.php
    RolePermissionEndpointsTest.php
  TestCase.php
phpunit.xml
```

# Files

## File: app/Enums/BranchType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum BranchType: string
 8: {
 9:     case Branch = 'branch';
10:     case Warehouse = 'warehouse';
11: }
```

## File: app/Enums/LocationType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum LocationType: string
 8: {
 9:     case Shelf = 'shelf';
10:     case Zone = 'zone';
11:     case Bin = 'bin';
12:     case Damaged = 'damaged';
13:     case Receiving = 'receiving';
14: }
```

## File: app/Enums/StaffRole.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum StaffRole: string
 8: {
 9:     case SuperAdmin = 'Super Admin';
10: }
```

## File: app/Http/Controllers/Api/V1/Admin/AuthController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Api\V1\Admin\AdminLoginRequest;
 9: use App\Http\Resources\Api\V1\Admin\UserResource;
10: use App\Models\User;
11: use Illuminate\Http\JsonResponse;
12: use Illuminate\Http\Request;
13: use Illuminate\Support\Facades\Hash;
14: use Illuminate\Http\Resources\Json\JsonResource;
15: 
16: class AuthController extends Controller
17: {
18:     public function login(AdminLoginRequest $request): JsonResource|JsonResponse
19:     {
20:         $credentials = $request->validated();
21:         $user = User::query()->where('email', $credentials['email'])->first();
22: 
23:         if (! $user instanceof User || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
24:             return response()->json([
25:                 'message' => 'بيانات تسجيل الدخول غير صحيحة أو الحساب غير نشط.',
26:             ], 401);
27:         }
28: 
29:         $user->load(['branch', 'roles.permissions', 'permissions']);
30:         $token = $user->createToken('staff-token')->plainTextToken;
31: 
32:         return UserResource::make($user)->additional(['token' => $token]);
33:     }
34: 
35:     public function me(Request $request): JsonResource
36:     {
37:         $user = $request->user('staff');
38: 
39:         abort_unless($user instanceof User, 403);
40: 
41:         $user->load(['branch', 'roles.permissions', 'permissions']);
42: 
43:         return UserResource::make($user);
44:     }
45: }
```

## File: app/Http/Controllers/Api/V1/Admin/BranchController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Admin;
  6: 
  7: use App\Http\Controllers\Controller;
  8: use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
  9: use App\Http\Requests\Api\V1\Admin\UpdateBranchRequest;
 10: use App\Http\Resources\Api\V1\Admin\BranchResource;
 11: use App\Models\Branch;
 12: use Illuminate\Http\JsonResponse;
 13: use Illuminate\Http\Request;
 14: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
 15: use Illuminate\Http\Response;
 16: use Illuminate\Support\Facades\DB;
 17: use Illuminate\Support\Facades\Schema;
 18: 
 19: class BranchController extends Controller
 20: {
 21:     public function index(Request $request): AnonymousResourceCollection
 22:     {
 23:         $validated = $request->validate([
 24:             'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
 25:         ]);
 26: 
 27:         $branches = Branch::query()
 28:             ->withCount(['locations', 'users'])
 29:             ->orderBy('id')
 30:             ->paginate($validated['per_page'] ?? 15)
 31:             ->withQueryString();
 32: 
 33:         return BranchResource::collection($branches);
 34:     }
 35: 
 36:     public function store(StoreBranchRequest $request): JsonResponse
 37:     {
 38:         $branch = Branch::query()->create($request->validated());
 39:         $branch->loadCount(['locations', 'users']);
 40: 
 41:         return BranchResource::make($branch)->response()->setStatusCode(201);
 42:     }
 43: 
 44:     public function show(Branch $branch): BranchResource
 45:     {
 46:         return BranchResource::make($branch->loadCount(['locations', 'users']));
 47:     }
 48: 
 49:     public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
 50:     {
 51:         $branch->update($request->validated());
 52: 
 53:         return BranchResource::make($branch->loadCount(['locations', 'users']));
 54:     }
 55: 
 56:     public function destroy(Branch $branch): JsonResponse|Response
 57:     {
 58:         $deleted = DB::transaction(function () use ($branch): bool {
 59:             $lockedBranch = Branch::query()
 60:                 ->whereKey($branch->getKey())
 61:                 ->lockForUpdate()
 62:                 ->firstOrFail();
 63: 
 64:             if ($lockedBranch->users()->exists() || $this->hasInventoryMovements($lockedBranch)) {
 65:                 return false;
 66:             }
 67: 
 68:             $lockedBranch->delete();
 69: 
 70:             return true;
 71:         });
 72: 
 73:         if (! $deleted) {
 74:             return response()->json([
 75:                 'message' => 'لا يمكن حذف الفرع لارتباطه بموظفين أو حركات مخزنية.',
 76:             ], 409);
 77:         }
 78: 
 79:         return response()->noContent();
 80:     }
 81: 
 82:     private function hasInventoryMovements(Branch $branch): bool
 83:     {
 84:         foreach (['inventory_movements', 'stock_movements', 'inventory_transactions', 'stock_transactions'] as $table) {
 85:             if (! Schema::hasTable($table)) {
 86:                 continue;
 87:             }
 88: 
 89:             if (Schema::hasColumn($table, 'branch_id') && DB::table($table)->where('branch_id', $branch->getKey())->exists()) {
 90:                 return true;
 91:             }
 92: 
 93:             if (Schema::hasColumn($table, 'location_id')) {
 94:                 $locationIds = $branch->locations()->select('locations.id');
 95: 
 96:                 if (DB::table($table)->whereIn('location_id', $locationIds)->exists()) {
 97:                     return true;
 98:                 }
 99:             }
100:         }
101: 
102:         return false;
103:     }
104: }
```

## File: app/Http/Controllers/Api/V1/Admin/LocationController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Api\V1\Admin\StoreLocationRequest;
 9: use App\Http\Requests\Api\V1\Admin\UpdateLocationRequest;
10: use App\Http\Resources\Api\V1\Admin\LocationResource;
11: use App\Models\Branch;
12: use App\Models\Location;
13: use Illuminate\Http\JsonResponse;
14: use Illuminate\Http\Request;
15: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
16: use Illuminate\Http\Response;
17: 
18: class LocationController extends Controller
19: {
20:     public function index(Request $request, Branch $branch): AnonymousResourceCollection
21:     {
22:         $validated = $request->validate([
23:             'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
24:         ]);
25: 
26:         $locations = $branch->locations()
27:             ->orderBy('id')
28:             ->paginate($validated['per_page'] ?? 15)
29:             ->withQueryString();
30: 
31:         return LocationResource::collection($locations);
32:     }
33: 
34:     public function store(StoreLocationRequest $request, Branch $branch): JsonResponse
35:     {
36:         $location = $branch->locations()->create($request->validated());
37: 
38:         return LocationResource::make($location)->response()->setStatusCode(201);
39:     }
40: 
41:     public function show(Branch $branch, Location $location): LocationResource
42:     {
43:         $this->ensureLocationBelongsToBranch($branch, $location);
44: 
45:         return LocationResource::make($location);
46:     }
47: 
48:     public function update(UpdateLocationRequest $request, Branch $branch, Location $location): LocationResource
49:     {
50:         $this->ensureLocationBelongsToBranch($branch, $location);
51:         $location->update($request->validated());
52: 
53:         return LocationResource::make($location->refresh());
54:     }
55: 
56:     public function destroy(Branch $branch, Location $location): JsonResponse|Response
57:     {
58:         $this->ensureLocationBelongsToBranch($branch, $location);
59:         $location->delete();
60: 
61:         return response()->noContent();
62:     }
63: 
64:     private function ensureLocationBelongsToBranch(Branch $branch, Location $location): void
65:     {
66:         abort_unless($location->branch_id === $branch->getKey(), 404);
67:     }
68: }
```

## File: app/Http/Controllers/Api/V1/Admin/PermissionController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use Illuminate\Http\JsonResponse;
 9: use Illuminate\Support\Str;
10: use Spatie\Permission\Models\Permission;
11: 
12: class PermissionController extends Controller
13: {
14:     public function index(): JsonResponse
15:     {
16:         $permissions = Permission::query()
17:             ->where('guard_name', 'staff')
18:             ->orderBy('name')
19:             ->get(['id', 'name', 'guard_name']);
20: 
21:         $groups = $permissions
22:             ->groupBy(fn (Permission $permission): string => Str::before($permission->name, '.'))
23:             ->map(fn ($group) => $group->map(fn (Permission $permission): array => [
24:                 'id' => $permission->id,
25:                 'name' => $permission->name,
26:             ])->values());
27: 
28:         return response()->json(['data' => $groups]);
29:     }
30: }
```

## File: app/Http/Controllers/Api/V1/Admin/RoleController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Admin;
  6: 
  7: use App\Enums\StaffRole;
  8: use App\Http\Controllers\Controller;
  9: use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
 10: use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
 11: use App\Http\Resources\Api\V1\Admin\RoleResource;
 12: use Illuminate\Http\JsonResponse;
 13: use Illuminate\Http\Response;
 14: use Illuminate\Http\Request;
 15: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
 16: use Illuminate\Support\Facades\DB;
 17: use Spatie\Permission\Models\Role;
 18: 
 19: class RoleController extends Controller
 20: {
 21:     public function index(Request $request): AnonymousResourceCollection
 22:     {
 23:         $validated = $request->validate([
 24:             'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
 25:         ]);
 26: 
 27:         $roles = Role::query()
 28:             ->where('guard_name', 'staff')
 29:             ->with('permissions')
 30:             ->orderBy('id')
 31:             ->paginate($validated['per_page'] ?? 15)
 32:             ->withQueryString();
 33: 
 34:         return RoleResource::collection($roles);
 35:     }
 36: 
 37:     public function store(StoreRoleRequest $request): JsonResponse
 38:     {
 39:         $validated = $request->validated();
 40: 
 41:         $role = DB::transaction(function () use ($validated): Role {
 42:             $role = Role::create([
 43:                 'name' => $validated['name'],
 44:                 'guard_name' => 'staff',
 45:             ]);
 46:             $role->syncPermissions($validated['permissions'] ?? []);
 47: 
 48:             return $role->load('permissions');
 49:         });
 50: 
 51:         return RoleResource::make($role)->response()->setStatusCode(201);
 52:     }
 53: 
 54:     public function show(Role $role): RoleResource
 55:     {
 56:         $this->ensureStaffRole($role);
 57: 
 58:         return RoleResource::make($role->load('permissions'));
 59:     }
 60: 
 61:     public function update(UpdateRoleRequest $request, Role $role): RoleResource
 62:     {
 63:         $this->ensureStaffRole($role);
 64:         abort_if(
 65:             $role->name === StaffRole::SuperAdmin->value,
 66:             403,
 67:             'لا يمكن تعديل دور Super Admin الأساسي.',
 68:         );
 69:         $validated = $request->validated();
 70: 
 71:         DB::transaction(function () use ($role, $validated): void {
 72:             if (array_key_exists('name', $validated)) {
 73:                 $role->name = $validated['name'];
 74:                 $role->save();
 75:             }
 76: 
 77:             if (array_key_exists('permissions', $validated)) {
 78:                 $role->syncPermissions($validated['permissions']);
 79:             }
 80:         });
 81: 
 82:         return RoleResource::make($role->load('permissions'));
 83:     }
 84: 
 85:     public function destroy(Role $role): JsonResponse|Response
 86:     {
 87:         $this->ensureStaffRole($role);
 88: 
 89:         if ($role->name === StaffRole::SuperAdmin->value) {
 90:             return response()->json([
 91:                 'message' => 'لا يمكن حذف دور Super Admin الأساسي.',
 92:             ], 403);
 93:         }
 94: 
 95:         $role->delete();
 96: 
 97:         return response()->noContent();
 98:     }
 99: 
100:     private function ensureStaffRole(Role $role): void
101:     {
102:         abort_unless($role->guard_name === 'staff', 404);
103:     }
104: }
```

## File: app/Http/Controllers/Api/V1/Admin/SettingController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
 9: use App\Services\SettingService;
10: use Illuminate\Http\JsonResponse;
11: 
12: class SettingController extends Controller
13: {
14:     public function __construct(private readonly SettingService $settings) {}
15: 
16:     public function index(): JsonResponse
17:     {
18:         return response()->json([
19:             'data' => $this->settings->getAllGrouped(),
20:         ]);
21:     }
22: 
23:     public function update(UpdateSettingsRequest $request): JsonResponse
24:     {
25:         $this->settings->updateBatch($request->validated());
26: 
27:         return response()->json([
28:             'data' => $this->settings->getAllGrouped(),
29:         ]);
30:     }
31: }
```

## File: app/Http/Controllers/Api/V1/Admin/UserController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Admin;
  6: 
  7: use App\Enums\StaffRole;
  8: use App\Http\Controllers\Controller;
  9: use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
 10: use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
 11: use App\Http\Resources\Api\V1\Admin\UserResource;
 12: use App\Models\User;
 13: use Illuminate\Http\JsonResponse;
 14: use Illuminate\Http\Request;
 15: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
 16: use Illuminate\Support\Arr;
 17: use Illuminate\Support\Facades\DB;
 18: use Illuminate\Validation\Rule;
 19: 
 20: class UserController extends Controller
 21: {
 22:     public function index(Request $request): AnonymousResourceCollection
 23:     {
 24:         $filters = $request->validate([
 25:             'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')],
 26:             'role' => [
 27:                 'sometimes',
 28:                 'nullable',
 29:                 'string',
 30:                 Rule::exists('roles', 'name')->where('guard_name', 'staff'),
 31:             ],
 32:             'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
 33:         ]);
 34: 
 35:         $users = User::query()
 36:             ->with(['branch', 'roles.permissions', 'permissions'])
 37:             ->when(array_key_exists('branch_id', $filters), function ($query) use ($filters): void {
 38:                 if ($filters['branch_id'] === null) {
 39:                     $query->whereNull('branch_id');
 40: 
 41:                     return;
 42:                 }
 43: 
 44:                 $query->where('branch_id', $filters['branch_id']);
 45:             })
 46:             ->when(! empty($filters['role']), function ($query) use ($filters): void {
 47:                 $query->whereHas('roles', function ($roles) use ($filters): void {
 48:                     $roles->where('name', $filters['role'])->where('guard_name', 'staff');
 49:                 });
 50:             })
 51:             ->orderBy('id')
 52:             ->paginate($filters['per_page'] ?? 15)
 53:             ->withQueryString();
 54: 
 55:         return UserResource::collection($users);
 56:     }
 57: 
 58:     public function store(StoreUserRequest $request): JsonResponse
 59:     {
 60:         $validated = $request->validated();
 61:         $roles = $validated['roles'] ?? [];
 62:         $userAttributes = Arr::except($validated, ['roles']);
 63:         $userAttributes['is_active'] ??= true;
 64: 
 65:         $user = DB::transaction(function () use ($userAttributes, $roles): User {
 66:             $user = User::create($userAttributes);
 67:             $user->syncRoles($roles);
 68: 
 69:             return $user->load(['branch', 'roles.permissions', 'permissions']);
 70:         });
 71: 
 72:         return UserResource::make($user)->response()->setStatusCode(201);
 73:     }
 74: 
 75:     public function show(User $user): UserResource
 76:     {
 77:         return UserResource::make($user->load(['branch', 'roles.permissions', 'permissions']));
 78:     }
 79: 
 80:     public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
 81:     {
 82:         $validated = $request->validated();
 83: 
 84:         if (
 85:             $user->hasRole(StaffRole::SuperAdmin->value, 'staff')
 86:             && (
 87:                 (array_key_exists('is_active', $validated) && ! $request->boolean('is_active'))
 88:                 || (array_key_exists('roles', $validated)
 89:                     && ! in_array(StaffRole::SuperAdmin->value, $validated['roles'], true))
 90:             )
 91:         ) {
 92:             return response()->json([
 93:                 'message' => 'لا يمكن تعطيل حساب Super Admin الأساسي أو إزالة دوره.',
 94:             ], 403);
 95:         }
 96: 
 97:         $roles = $validated['roles'] ?? null;
 98:         $userAttributes = Arr::except($validated, ['roles']);
 99: 
100:         if (array_key_exists('password', $userAttributes) && $userAttributes['password'] === null) {
101:             unset($userAttributes['password']);
102:         }
103: 
104:         DB::transaction(function () use ($user, $userAttributes, $roles): void {
105:             $user->update($userAttributes);
106: 
107:             if ($roles !== null) {
108:                 $user->syncRoles($roles);
109:             }
110:         });
111: 
112:         return UserResource::make($user->load(['branch', 'roles.permissions', 'permissions']));
113:     }
114: 
115:     public function destroy(Request $request, User $user): JsonResponse
116:     {
117:         $currentUser = $request->user('staff');
118: 
119:         if ($currentUser instanceof User && $currentUser->is($user)) {
120:             return response()->json([
121:                 'message' => 'لا يمكنك تعطيل حسابك الحالي.',
122:             ], 403);
123:         }
124: 
125:         if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
126:             return response()->json([
127:                 'message' => 'لا يمكن تعطيل حساب Super Admin الأساسي.',
128:             ], 403);
129:         }
130: 
131:         DB::transaction(function () use ($user): void {
132:             $user->forceFill(['is_active' => false])->save();
133:             $user->tokens()->delete();
134:         });
135: 
136:         return response()->json([
137:             'message' => 'تم تعطيل حساب الموظف وإلغاء رموزه.',
138:             'data' => UserResource::make($user->load(['branch', 'roles.permissions', 'permissions']))->resolve($request),
139:         ]);
140:     }
141: }
```

## File: app/Http/Controllers/Controller.php
```php
1: <?php
2: 
3: namespace App\Http\Controllers;
4: 
5: abstract class Controller
6: {
7:     //
8: }
```

## File: app/Http/Middleware/EnsureStaffAuthenticated.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Models\User;
 8: use Closure;
 9: use Illuminate\Http\Request;
10: use Illuminate\Support\Facades\Auth;
11: use Symfony\Component\HttpFoundation\Response;
12: 
13: class EnsureStaffAuthenticated
14: {
15:     public function handle(Request $request, Closure $next): Response
16:     {
17:         Auth::shouldUse('staff');
18:         $user = $request->user('staff');
19: 
20:         if (! $user instanceof User || ! $user->is_active) {
21:             return response()->json([
22:                 'message' => 'غير مصرح لك بالدخول كموظف أو الحساب غير نشط.',
23:             ], 403);
24:         }
25: 
26:         return $next($request);
27:     }
28: }
```

## File: app/Http/Requests/Api/V1/Admin/AdminLoginRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: 
 9: class AdminLoginRequest extends FormRequest
10: {
11:     public function authorize(): bool
12:     {
13:         return true;
14:     }
15: 
16:     /**
17:      * @return array<string, list<string>>
18:      */
19:     public function rules(): array
20:     {
21:         return [
22:             'email' => ['required', 'email', 'max:255'],
23:             'password' => ['required', 'string', 'max:255'],
24:         ];
25:     }
26: }
```

## File: app/Http/Requests/Api/V1/Admin/StoreBranchRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use App\Enums\BranchType;
 8: use Illuminate\Contracts\Validation\ValidationRule;
 9: use Illuminate\Foundation\Http\FormRequest;
10: use Illuminate\Validation\Rule;
11: 
12: class StoreBranchRequest extends FormRequest
13: {
14:     public function authorize(): bool
15:     {
16:         return $this->user() !== null;
17:     }
18: 
19:     /**
20:      * @return array<string, array<mixed>|string|ValidationRule>
21:      */
22:     public function rules(): array
23:     {
24:         return [
25:             'name' => ['required', 'string', 'max:255'],
26:             'code' => ['required', 'string', 'max:50', Rule::unique('branches', 'code')],
27:             'type' => ['required', Rule::enum(BranchType::class)],
28:             'phone' => ['nullable', 'string', 'max:50'],
29:             'email' => ['nullable', 'email', 'max:255'],
30:             'address' => ['nullable', 'string', 'max:255'],
31:             'city' => ['nullable', 'string', 'max:120'],
32:             'is_active' => ['sometimes', 'boolean'],
33:         ];
34:     }
35: }
```

## File: app/Http/Requests/Api/V1/Admin/StoreLocationRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use App\Enums\LocationType;
 8: use App\Models\Branch;
 9: use Illuminate\Contracts\Validation\ValidationRule;
10: use Illuminate\Foundation\Http\FormRequest;
11: use Illuminate\Validation\Rule;
12: 
13: class StoreLocationRequest extends FormRequest
14: {
15:     public function authorize(): bool
16:     {
17:         return $this->user() !== null;
18:     }
19: 
20:     /**
21:      * @return array<string, array<mixed>|string|ValidationRule>
22:      */
23:     public function rules(): array
24:     {
25:         $branch = $this->route('branch');
26:         $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;
27: 
28:         return [
29:             'name' => ['required', 'string', 'max:255'],
30:             'code' => [
31:                 'required',
32:                 'string',
33:                 'max:100',
34:                 Rule::unique('locations', 'code')->where('branch_id', $branchId),
35:             ],
36:             'type' => ['required', Rule::enum(LocationType::class)],
37:             'is_active' => ['sometimes', 'boolean'],
38:         ];
39:     }
40: }
```

## File: app/Http/Requests/Api/V1/Admin/StoreRoleRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class StoreRoleRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return $this->user() !== null;
15:     }
16: 
17:     /**
18:      * @return array<string, array<int, mixed>>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'name' => [
24:                 'required',
25:                 'string',
26:                 'max:255',
27:                 Rule::unique('roles', 'name')->where('guard_name', 'staff'),
28:             ],
29:             'permissions' => ['sometimes', 'array'],
30:             'permissions.*' => [
31:                 'string',
32:                 'distinct',
33:                 Rule::exists('permissions', 'name')->where('guard_name', 'staff'),
34:             ],
35:         ];
36:     }
37: }
```

## File: app/Http/Requests/Api/V1/Admin/StoreUserRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class StoreUserRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return $this->user() !== null;
15:     }
16: 
17:     /**
18:      * @return array<string, array<int, mixed>>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'name' => ['required', 'string', 'max:255'],
24:             'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
25:             'phone' => ['nullable', 'string', 'max:30'],
26:             'password' => ['required', 'string', 'min:8', 'max:255'],
27:             'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
28:             'is_active' => ['sometimes', 'boolean'],
29:             'roles' => ['sometimes', 'array'],
30:             'roles.*' => [
31:                 'string',
32:                 'distinct',
33:                 Rule::exists('roles', 'name')->where('guard_name', 'staff'),
34:             ],
35:         ];
36:     }
37: }
```

## File: app/Http/Requests/Api/V1/Admin/UpdateBranchRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use App\Enums\BranchType;
 8: use App\Models\Branch;
 9: use Illuminate\Contracts\Validation\ValidationRule;
10: use Illuminate\Foundation\Http\FormRequest;
11: use Illuminate\Validation\Rule;
12: 
13: class UpdateBranchRequest extends FormRequest
14: {
15:     public function authorize(): bool
16:     {
17:         return $this->user() !== null;
18:     }
19: 
20:     /**
21:      * @return array<string, array<mixed>|string|ValidationRule>
22:      */
23:     public function rules(): array
24:     {
25:         $branch = $this->route('branch');
26: 
27:         return [
28:             'name' => ['sometimes', 'required', 'string', 'max:255'],
29:             'code' => [
30:                 'sometimes',
31:                 'required',
32:                 'string',
33:                 'max:50',
34:                 Rule::unique('branches', 'code')->ignore($branch instanceof Branch ? $branch : null),
35:             ],
36:             'type' => ['sometimes', 'required', Rule::enum(BranchType::class)],
37:             'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
38:             'email' => ['sometimes', 'nullable', 'email', 'max:255'],
39:             'address' => ['sometimes', 'nullable', 'string', 'max:255'],
40:             'city' => ['sometimes', 'nullable', 'string', 'max:120'],
41:             'is_active' => ['sometimes', 'boolean'],
42:         ];
43:     }
44: }
```

## File: app/Http/Requests/Api/V1/Admin/UpdateLocationRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use App\Enums\LocationType;
 8: use App\Models\Branch;
 9: use App\Models\Location;
10: use Illuminate\Contracts\Validation\ValidationRule;
11: use Illuminate\Foundation\Http\FormRequest;
12: use Illuminate\Validation\Rule;
13: 
14: class UpdateLocationRequest extends FormRequest
15: {
16:     public function authorize(): bool
17:     {
18:         return $this->user() !== null;
19:     }
20: 
21:     /**
22:      * @return array<string, array<mixed>|string|ValidationRule>
23:      */
24:     public function rules(): array
25:     {
26:         $branch = $this->route('branch');
27:         $location = $this->route('location');
28:         $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;
29: 
30:         return [
31:             'name' => ['sometimes', 'required', 'string', 'max:255'],
32:             'code' => [
33:                 'sometimes',
34:                 'required',
35:                 'string',
36:                 'max:100',
37:                 Rule::unique('locations', 'code')
38:                     ->where('branch_id', $branchId)
39:                     ->ignore($location instanceof Location ? $location : null),
40:             ],
41:             'type' => ['sometimes', 'required', Rule::enum(LocationType::class)],
42:             'is_active' => ['sometimes', 'boolean'],
43:         ];
44:     }
45: }
```

## File: app/Http/Requests/Api/V1/Admin/UpdateRoleRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class UpdateRoleRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return $this->user() !== null;
15:     }
16: 
17:     /**
18:      * @return array<string, array<int, mixed>>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'name' => [
24:                 'sometimes',
25:                 'required',
26:                 'string',
27:                 'max:255',
28:                 Rule::unique('roles', 'name')
29:                     ->where('guard_name', 'staff')
30:                     ->ignore($this->route('role')),
31:             ],
32:             'permissions' => ['sometimes', 'array'],
33:             'permissions.*' => [
34:                 'string',
35:                 'distinct',
36:                 Rule::exists('permissions', 'name')->where('guard_name', 'staff'),
37:             ],
38:         ];
39:     }
40: }
```

## File: app/Http/Requests/Api/V1/Admin/UpdateSettingsRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: use Illuminate\Validation\Validator;
10: 
11: class UpdateSettingsRequest extends FormRequest
12: {
13:     public function authorize(): bool
14:     {
15:         return $this->user() !== null;
16:     }
17: 
18:     /**
19:      * @return array<string, array<mixed>|string|ValidationRule>
20:      */
21:     public function rules(): array
22:     {
23:         return [
24:             'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
25:             'company_email' => ['sometimes', 'nullable', 'email', 'max:255'],
26:             'company_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
27:             'company_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
28:             'company_logo' => ['sometimes', 'nullable', 'string', 'max:2048'],
29:             'commercial_register' => ['sometimes', 'nullable', 'string', 'max:100'],
30: 
31:             'tax_enabled' => ['sometimes', 'boolean'],
32:             'tax_number' => ['sometimes', 'nullable', 'string', 'max:100'],
33:             'default_tax_rate' => ['sometimes', 'numeric', 'between:0,100'],
34:             'prices_include_tax' => ['sometimes', 'boolean'],
35: 
36:             'invoice_prefix' => ['sometimes', 'string', 'max:30'],
37:             'invoice_next_number' => ['sometimes', 'integer', 'min:1'],
38:             'invoice_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
39:             'order_prefix' => ['sometimes', 'string', 'max:30'],
40:             'order_next_number' => ['sometimes', 'integer', 'min:1'],
41:             'order_padding' => ['sometimes', 'integer', 'min:1', 'max:20'],
42:             'purchase_order_prefix' => ['sometimes', 'string', 'max:30'],
43:             'purchase_order_next_number' => ['sometimes', 'integer', 'min:1'],
44:             'quotation_prefix' => ['sometimes', 'string', 'max:30'],
45:             'quotation_next_number' => ['sometimes', 'integer', 'min:1'],
46:         ];
47:     }
48: 
49:     public function withValidator(Validator $validator): void
50:     {
51:         $allowedKeys = array_keys($this->rules());
52:         $unknownKeys = array_diff(array_keys($this->all()), $allowedKeys);
53: 
54:         $validator->after(function (Validator $validator) use ($unknownKeys): void {
55:             foreach ($unknownKeys as $unknownKey) {
56:                 $validator->errors()->add($unknownKey, 'إعداد غير معروف.');
57:             }
58:         });
59:     }
60: }
```

## File: app/Http/Requests/Api/V1/Admin/UpdateUserRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Api\V1\Admin;
 6: 
 7: use Illuminate\Foundation\Http\FormRequest;
 8: use Illuminate\Validation\Rule;
 9: 
10: class UpdateUserRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return $this->user() !== null;
15:     }
16: 
17:     /**
18:      * @return array<string, array<int, mixed>>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'name' => ['sometimes', 'required', 'string', 'max:255'],
24:             'email' => [
25:                 'sometimes',
26:                 'required',
27:                 'email',
28:                 'max:255',
29:                 Rule::unique('users', 'email')->ignore($this->route('user')),
30:             ],
31:             'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
32:             'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],
33:             'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')],
34:             'is_active' => ['sometimes', 'boolean'],
35:             'roles' => ['sometimes', 'array'],
36:             'roles.*' => [
37:                 'string',
38:                 'distinct',
39:                 Rule::exists('roles', 'name')->where('guard_name', 'staff'),
40:             ],
41:         ];
42:     }
43: }
```

## File: app/Http/Resources/Api/V1/Admin/BranchResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Api\V1\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: class BranchResource extends JsonResource
11: {
12:     /**
13:      * @return array<string, mixed>
14:      */
15:     public function toArray(Request $request): array
16:     {
17:         return [
18:             'id' => $this->id,
19:             'name' => $this->name,
20:             'code' => $this->code,
21:             'type' => $this->type->value,
22:             'phone' => $this->phone,
23:             'email' => $this->email,
24:             'address' => $this->address,
25:             'city' => $this->city,
26:             'is_active' => $this->is_active,
27:             'locations_count' => $this->whenCounted('locations'),
28:             'employees_count' => $this->whenCounted('users'),
29:             'created_at' => $this->created_at,
30:             'updated_at' => $this->updated_at,
31:         ];
32:     }
33: }
```

## File: app/Http/Resources/Api/V1/Admin/LocationResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Api\V1\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: class LocationResource extends JsonResource
11: {
12:     /**
13:      * @return array<string, mixed>
14:      */
15:     public function toArray(Request $request): array
16:     {
17:         return [
18:             'id' => $this->id,
19:             'branch_id' => $this->branch_id,
20:             'name' => $this->name,
21:             'code' => $this->code,
22:             'type' => $this->type->value,
23:             'is_active' => $this->is_active,
24:             'created_at' => $this->created_at,
25:             'updated_at' => $this->updated_at,
26:         ];
27:     }
28: }
```

## File: app/Http/Resources/Api/V1/Admin/RoleResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Api\V1\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: class RoleResource extends JsonResource
11: {
12:     /**
13:      * @return array<string, mixed>
14:      */
15:     public function toArray(Request $request): array
16:     {
17:         return [
18:             'id' => $this->id,
19:             'name' => $this->name,
20:             'guard_name' => $this->guard_name,
21:             'permissions' => $this->whenLoaded(
22:                 'permissions',
23:                 fn () => $this->permissions->pluck('name')->values(),
24:             ),
25:             'created_at' => $this->created_at,
26:             'updated_at' => $this->updated_at,
27:         ];
28:     }
29: }
```

## File: app/Http/Resources/Api/V1/Admin/UserResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Api\V1\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: class UserResource extends JsonResource
11: {
12:     /**
13:      * @return array<string, mixed>
14:      */
15:     public function toArray(Request $request): array
16:     {
17:         return [
18:             'id' => $this->id,
19:             'name' => $this->name,
20:             'email' => $this->email,
21:             'phone' => $this->phone,
22:             'branch_id' => $this->branch_id,
23:             'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null
24:                 ? null
25:                 : ['id' => $this->branch->id, 'name' => $this->branch->name]),
26:             'is_active' => $this->is_active,
27:             'roles' => $this->whenLoaded(
28:                 'roles',
29:                 fn () => $this->roles->map(fn ($role): array => [
30:                     'id' => $role->id,
31:                     'name' => $role->name,
32:                 ])->values(),
33:             ),
34:             'permissions' => $this->when(
35:                 $this->relationLoaded('roles') || $this->relationLoaded('permissions'),
36:                 fn () => $this->getAllPermissions()->pluck('name')->unique()->values(),
37:             ),
38:             'created_at' => $this->created_at,
39:             'updated_at' => $this->updated_at,
40:         ];
41:     }
42: }
```

## File: app/Models/Branch.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\BranchType;
 8: use Database\Factories\BranchFactory;
 9: use Illuminate\Database\Eloquent\Attributes\Fillable;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Database\Eloquent\Model;
12: use Illuminate\Database\Eloquent\Relations\HasMany;
13: 
14: #[Fillable(['name', 'code', 'type', 'phone', 'email', 'address', 'city', 'is_active'])]
15: class Branch extends Model
16: {
17:     /** @use HasFactory<BranchFactory> */
18:     use HasFactory;
19: 
20:     public function locations(): HasMany
21:     {
22:         return $this->hasMany(Location::class);
23:     }
24: 
25:     public function users(): HasMany
26:     {
27:         return $this->hasMany(User::class);
28:     }
29: 
30:     /**
31:      * @return array<string, string>
32:      */
33:     protected function casts(): array
34:     {
35:         return [
36:             'type' => BranchType::class,
37:             'is_active' => 'boolean',
38:         ];
39:     }
40: }
```

## File: app/Models/Location.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\LocationType;
 8: use Database\Factories\LocationFactory;
 9: use Illuminate\Database\Eloquent\Attributes\Fillable;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Database\Eloquent\Model;
12: use Illuminate\Database\Eloquent\Relations\BelongsTo;
13: 
14: #[Fillable(['name', 'code', 'type', 'is_active'])]
15: class Location extends Model
16: {
17:     /** @use HasFactory<LocationFactory> */
18:     use HasFactory;
19: 
20:     public function branch(): BelongsTo
21:     {
22:         return $this->belongsTo(Branch::class);
23:     }
24: 
25:     /**
26:      * @return array<string, string>
27:      */
28:     protected function casts(): array
29:     {
30:         return [
31:             'type' => LocationType::class,
32:             'is_active' => 'boolean',
33:         ];
34:     }
35: }
```

## File: app/Models/Setting.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Model;
 8: 
 9: class Setting extends Model
10: {
11:     /** @var list<string> */
12:     protected $fillable = [
13:         'group',
14:         'key',
15:         'value',
16:         'type',
17:     ];
18: }
```

## File: app/Models/User.php
```php
 1: <?php
 2: 
 3: namespace App\Models;
 4: 
 5: use Illuminate\Database\Eloquent\Relations\BelongsTo;
 6: // use Illuminate\Contracts\Auth\MustVerifyEmail;
 7: use Database\Factories\UserFactory;
 8: use Illuminate\Database\Eloquent\Attributes\Fillable;
 9: use Illuminate\Database\Eloquent\Attributes\Hidden;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Foundation\Auth\User as Authenticatable;
12: use Illuminate\Notifications\Notifiable;
13: use Laravel\Sanctum\HasApiTokens;
14: use Spatie\Permission\Traits\HasRoles;
15: 
16: #[Fillable(['name', 'email', 'phone', 'password', 'branch_id', 'is_active'])]
17: #[Hidden(['password', 'remember_token'])]
18: class User extends Authenticatable
19: {
20:     /** @use HasFactory<UserFactory> */
21:     use HasApiTokens, HasFactory, HasRoles, Notifiable;
22: 
23:     protected string $guard_name = 'staff';
24: 
25:     public function branch(): BelongsTo
26:     {
27:         return $this->belongsTo(Branch::class);
28:     }
29: 
30:     /**
31:      * Get the attributes that should be cast.
32:      *
33:      * @return array<string, string>
34:      */
35:     protected function casts(): array
36:     {
37:         return [
38:             'email_verified_at' => 'datetime',
39:             'password' => 'hashed',
40:             'is_active' => 'boolean',
41:         ];
42:     }
43: }
```

## File: app/Services/SettingService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Models\Setting;
  8: use Illuminate\Support\Facades\Cache;
  9: use Illuminate\Support\Facades\DB;
 10: use InvalidArgumentException;
 11: use JsonException;
 12: 
 13: class SettingService
 14: {
 15:     private const CACHE_KEY = 'app_settings';
 16: 
 17:     /**
 18:      * Return all settings grouped by their group name, with values cast to their declared types.
 19:      *
 20:      * @return array<string, array<string, mixed>>
 21:      */
 22:     public function getAllGrouped(): array
 23:     {
 24:         return Cache::rememberForever(self::CACHE_KEY, function (): array {
 25:             return Setting::query()
 26:                 ->orderBy('group')
 27:                 ->orderBy('key')
 28:                 ->get()
 29:                 ->groupBy('group')
 30:                 ->map(fn ($settings): array => $settings
 31:                     ->mapWithKeys(fn (Setting $setting): array => [
 32:                         $setting->key => $this->castValue($setting->value, $setting->type),
 33:                     ])
 34:                     ->all())
 35:                 ->all();
 36:         });
 37:     }
 38: 
 39:     public function get(string $key, mixed $default = null): mixed
 40:     {
 41:         foreach ($this->getAllGrouped() as $settings) {
 42:             if (array_key_exists($key, $settings)) {
 43:                 return $settings[$key];
 44:             }
 45:         }
 46: 
 47:         return $default;
 48:     }
 49: 
 50:     /**
 51:      * Update a flat key/value map atomically and invalidate the cached settings afterward.
 52:      *
 53:      * @param  array<string, mixed>  $settings
 54:      *
 55:      * @throws JsonException
 56:      */
 57:     public function updateBatch(array $settings): void
 58:     {
 59:         DB::transaction(function () use ($settings): void {
 60:             foreach ($settings as $key => $value) {
 61:                 $setting = Setting::query()
 62:                     ->where('key', $key)
 63:                     ->lockForUpdate()
 64:                     ->first();
 65: 
 66:                 if (! $setting instanceof Setting) {
 67:                     throw new InvalidArgumentException("Unknown setting [{$key}].");
 68:                 }
 69: 
 70:                 $setting->value = $this->serializeValue($value, $setting->type);
 71:                 $setting->save();
 72:             }
 73:         }, attempts: 3);
 74: 
 75:         Cache::forget(self::CACHE_KEY);
 76:     }
 77: 
 78:     /**
 79:      * Atomically return and increment the next document number for the requested type.
 80:      *
 81:      * @throws JsonException
 82:      */
 83:     public function generateNextNumber(string $type): string
 84:     {
 85:         $definitions = [
 86:             'invoice' => ['prefix' => 'invoice_prefix', 'next' => 'invoice_next_number', 'padding' => 'invoice_padding'],
 87:             'order' => ['prefix' => 'order_prefix', 'next' => 'order_next_number', 'padding' => 'order_padding'],
 88:             'purchase_order' => ['prefix' => 'purchase_order_prefix', 'next' => 'purchase_order_next_number', 'padding' => null],
 89:             'quotation' => ['prefix' => 'quotation_prefix', 'next' => 'quotation_next_number', 'padding' => null],
 90:         ];
 91: 
 92:         if (! isset($definitions[$type])) {
 93:             throw new InvalidArgumentException("Unsupported numbering type [{$type}].");
 94:         }
 95: 
 96:         $definition = $definitions[$type];
 97:         $keys = array_values(array_filter([
 98:             $definition['prefix'],
 99:             $definition['next'],
100:             $definition['padding'],
101:         ]));
102: 
103:         $number = DB::transaction(function () use ($definition, $keys): string {
104:             $settings = Setting::query()
105:                 ->whereIn('key', $keys)
106:                 ->orderBy('key')
107:                 ->lockForUpdate()
108:                 ->get()
109:                 ->keyBy('key');
110: 
111:             foreach ($keys as $key) {
112:                 if (! $settings->has($key)) {
113:                     throw new InvalidArgumentException("Required numbering setting [{$key}] is missing.");
114:                 }
115:             }
116: 
117:             $prefix = (string) $settings->get($definition['prefix'])->value;
118:             $nextSetting = $settings->get($definition['next']);
119:             $nextValue = (int) $nextSetting->value;
120:             $padding = $definition['padding'] === null
121:                 ? 0
122:                 : max(0, (int) $settings->get($definition['padding'])->value);
123: 
124:             $formatted = $prefix.str_pad((string) $nextValue, $padding, '0', STR_PAD_LEFT);
125:             $nextSetting->value = (string) ($nextValue + 1);
126:             $nextSetting->save();
127: 
128:             return $formatted;
129:         }, attempts: 3);
130: 
131:         Cache::forget(self::CACHE_KEY);
132: 
133:         return $number;
134:     }
135: 
136:     private function castValue(?string $value, string $type): mixed
137:     {
138:         if ($value === null) {
139:             return null;
140:         }
141: 
142:         return match ($type) {
143:             'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
144:             'integer' => (int) $value,
145:             'float' => (float) $value,
146:             'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
147:             default => $value,
148:         };
149:     }
150: 
151:     /** @throws JsonException */
152:     private function serializeValue(mixed $value, string $type): ?string
153:     {
154:         if ($value === null) {
155:             return null;
156:         }
157: 
158:         if ($type === 'json') {
159:             return json_encode($value, JSON_THROW_ON_ERROR);
160:         }
161: 
162:         if ($type === 'boolean') {
163:             return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
164:         }
165: 
166:         return (string) $value;
167:     }
168: }
```

## File: bootstrap/app.php
```php
 1: <?php
 2: 
 3: use App\Http\Middleware\EnsureCustomerAuthenticated;
 4: use App\Http\Middleware\EnsureStaffAuthenticated;
 5: use App\Http\Middleware\EnsureSupplierAuthenticated;
 6: use App\Http\Middleware\EnsureWholesaleApproved;
 7: use Illuminate\Foundation\Application;
 8: use Illuminate\Foundation\Configuration\Exceptions;
 9: use Illuminate\Foundation\Configuration\Middleware;
10: use Illuminate\Http\Request;
11: use Illuminate\Support\Facades\Route;
12: use Spatie\Permission\Middleware\PermissionMiddleware;
13: use Spatie\Permission\Middleware\RoleMiddleware;
14: 
15: return Application::configure(basePath: dirname(__DIR__))
16:     ->withRouting(
17:         web: __DIR__.'/../routes/web.php',
18:         commands: __DIR__.'/../routes/console.php',
19:         health: '/up',
20:         then: function (): void {
21:             Route::middleware('api')
22:                 ->prefix('api/v1/admin')
23:                 ->group(base_path('routes/api/admin.php'));
24:         },
25:     )
26:     ->withMiddleware(function (Middleware $middleware): void {
27:         $middleware->alias([
28:             'auth.staff' => EnsureStaffAuthenticated::class,
29:             'auth.customer' => EnsureCustomerAuthenticated::class,
30:             'auth.supplier' => EnsureSupplierAuthenticated::class,
31:             'wholesale.approved' => EnsureWholesaleApproved::class,
32:             'role' => RoleMiddleware::class,
33:             'permission' => PermissionMiddleware::class,
34:         ]);
35:     })
36:     ->withExceptions(function (Exceptions $exceptions): void {
37:         $exceptions->shouldRenderJsonWhen(
38:             fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
39:         );
40:     })->create();
```

## File: config/auth.php
```php
  1: <?php
  2: 
  3: use App\Models\Customer;
  4: use App\Models\SupplierUser;
  5: use App\Models\User;
  6: 
  7: return [
  8: 
  9:     /*
 10:     |--------------------------------------------------------------------------
 11:     | Authentication Defaults
 12:     |--------------------------------------------------------------------------
 13:     |
 14:     | This option defines the default authentication "guard" and password
 15:     | reset "broker" for your application. You may change these values
 16:     | as required, but they're a perfect start for most applications.
 17:     |
 18:     */
 19: 
 20:     'defaults' => [
 21:         'guard' => env('AUTH_GUARD', 'web'),
 22:         'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
 23:     ],
 24: 
 25:     /*
 26:     |--------------------------------------------------------------------------
 27:     | Authentication Guards
 28:     |--------------------------------------------------------------------------
 29:     |
 30:     | Next, you may define every authentication guard for your application.
 31:     | Of course, a great default configuration has been defined for you
 32:     | which utilizes session storage plus the Eloquent user provider.
 33:     |
 34:     | All authentication guards have a user provider, which defines how the
 35:     | users are actually retrieved out of your database or other storage
 36:     | system used by the application. Typically, Eloquent is utilized.
 37:     |
 38:     | Supported: "session"
 39:     |
 40:     */
 41: 
 42:     'guards' => [
 43:         'web' => [
 44:             'driver' => 'session',
 45:             'provider' => 'users',
 46:         ],
 47:         'staff' => [
 48:             'driver' => 'sanctum',
 49:             'provider' => 'users',
 50:         ],
 51:         'customer' => [
 52:             'driver' => 'sanctum',
 53:             'provider' => 'customers',
 54:         ],
 55:         'supplier' => [
 56:             'driver' => 'sanctum',
 57:             'provider' => 'supplier_users',
 58:         ],
 59:     ],
 60: 
 61:     /*
 62:     |--------------------------------------------------------------------------
 63:     | User Providers
 64:     |--------------------------------------------------------------------------
 65:     |
 66:     | All authentication guards have a user provider, which defines how the
 67:     | users are actually retrieved out of your database or other storage
 68:     | system used by the application. Typically, Eloquent is utilized.
 69:     |
 70:     | If you have multiple user tables or models you may configure multiple
 71:     | providers to represent the model / table. These providers may then
 72:     | be assigned to any extra authentication guards you have defined.
 73:     |
 74:     | Supported: "database", "eloquent"
 75:     |
 76:     */
 77: 
 78:     'providers' => [
 79:         'users' => [
 80:             'driver' => 'eloquent',
 81:             'model' => env('AUTH_MODEL', User::class),
 82:         ],
 83: 
 84:         'customers' => [
 85:             'driver' => 'eloquent',
 86:             'model' => Customer::class,
 87:         ],
 88: 
 89:         'supplier_users' => [
 90:             'driver' => 'eloquent',
 91:             'model' => SupplierUser::class,
 92:         ],
 93: 
 94:         // 'users' => [
 95:         //     'driver' => 'database',
 96:         //     'table' => 'users',
 97:         // ],
 98:     ],
 99: 
100:     /*
101:     |--------------------------------------------------------------------------
102:     | Resetting Passwords
103:     |--------------------------------------------------------------------------
104:     |
105:     | These configuration options specify the behavior of Laravel's password
106:     | reset functionality, including the table utilized for token storage
107:     | and the user provider that is invoked to actually retrieve users.
108:     |
109:     | The expiry time is the number of minutes that each reset token will be
110:     | considered valid. This security feature keeps tokens short-lived so
111:     | they have less time to be guessed. You may change this as needed.
112:     |
113:     | The throttle setting is the number of seconds a user must wait before
114:     | generating more password reset tokens. This prevents the user from
115:     | quickly generating a very large amount of password reset tokens.
116:     |
117:     */
118: 
119:     'passwords' => [
120:         'users' => [
121:             'provider' => 'users',
122:             'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
123:             'expire' => 60,
124:             'throttle' => 60,
125:         ],
126:     ],
127: 
128:     /*
129:     |--------------------------------------------------------------------------
130:     | Password Confirmation Timeout
131:     |--------------------------------------------------------------------------
132:     |
133:     | Here you may define the number of seconds before a password confirmation
134:     | window expires and users are asked to re-enter their password via the
135:     | confirmation screen. By default, the timeout lasts for three hours.
136:     |
137:     */
138: 
139:     'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
140: 
141: ];
```

## File: config/permission.php
```php
  1: <?php
  2: 
  3: use Spatie\Permission\DefaultTeamResolver;
  4: use Spatie\Permission\Models\Permission;
  5: use Spatie\Permission\Models\Role;
  6: 
  7: return [
  8: 
  9:     'models' => [
 10: 
 11:         /*
 12:          * When using the "HasPermissions" trait from this package, we need to know which
 13:          * Eloquent model should be used to retrieve your permissions. Of course, it
 14:          * is often just the "Permission" model but you may use whatever you like.
 15:          *
 16:          * The model you want to use as a Permission model needs to implement the
 17:          * `Spatie\Permission\Contracts\Permission` contract.
 18:          */
 19: 
 20:         'permission' => Permission::class,
 21: 
 22:         /*
 23:          * When using the "HasRoles" trait from this package, we need to know which
 24:          * Eloquent model should be used to retrieve your roles. Of course, it
 25:          * is often just the "Role" model but you may use whatever you like.
 26:          *
 27:          * The model you want to use as a Role model needs to implement the
 28:          * `Spatie\Permission\Contracts\Role` contract.
 29:          */
 30: 
 31:         'role' => Role::class,
 32: 
 33:         /*
 34:          * When using the "Teams" feature from this package, we need to know which
 35:          * Eloquent model should be used to retrieve your teams. Of course, it
 36:          * is often just the "Team" model but you may use whatever you like.
 37:          */
 38:         'team' => null,
 39: 
 40:         /*
 41:          * When using the "HasModels" trait and passing raw IDs to syncModels,
 42:          * attachModels, or detachModels, this model class will be used to
 43:          * resolve those IDs. If null, defaults to the guard's model.
 44:          */
 45:         'default_model' => null,
 46:     ],
 47: 
 48:     'table_names' => [
 49: 
 50:         /*
 51:          * When using the "HasRoles" trait from this package, we need to know which
 52:          * table should be used to retrieve your roles. We have chosen a basic
 53:          * default value but you may easily change it to any table you like.
 54:          */
 55: 
 56:         'roles' => 'roles',
 57: 
 58:         /*
 59:          * When using the "HasPermissions" trait from this package, we need to know which
 60:          * table should be used to retrieve your permissions. We have chosen a basic
 61:          * default value but you may easily change it to any table you like.
 62:          */
 63: 
 64:         'permissions' => 'permissions',
 65: 
 66:         /*
 67:          * When using the "HasPermissions" trait from this package, we need to know which
 68:          * table should be used to retrieve your models permissions. We have chosen a
 69:          * basic default value but you may easily change it to any table you like.
 70:          */
 71: 
 72:         'model_has_permissions' => 'model_has_permissions',
 73: 
 74:         /*
 75:          * When using the "HasRoles" trait from this package, we need to know which
 76:          * table should be used to retrieve your models roles. We have chosen a
 77:          * basic default value but you may easily change it to any table you like.
 78:          */
 79: 
 80:         'model_has_roles' => 'model_has_roles',
 81: 
 82:         /*
 83:          * When using the "HasRoles" trait from this package, we need to know which
 84:          * table should be used to retrieve your roles permissions. We have chosen a
 85:          * basic default value but you may easily change it to any table you like.
 86:          */
 87: 
 88:         'role_has_permissions' => 'role_has_permissions',
 89:     ],
 90: 
 91:     'column_names' => [
 92:         /*
 93:          * Change this if you want to name the related pivots other than defaults
 94:          */
 95:         'role_pivot_key' => null, // default 'role_id',
 96:         'permission_pivot_key' => null, // default 'permission_id',
 97: 
 98:         /*
 99:          * Change this if you want to name the related model primary key other than
100:          * `model_id`.
101:          *
102:          * For example, this would be nice if your primary keys are all UUIDs. In
103:          * that case, name this `model_uuid`.
104:          */
105: 
106:         'model_morph_key' => 'model_id',
107: 
108:         /*
109:          * Change this if you want to use the teams feature and your related model's
110:          * foreign key is other than `team_id`.
111:          */
112: 
113:         'team_foreign_key' => 'team_id',
114:     ],
115: 
116:     /*
117:      * When set to true, the method for checking permissions will be registered on the gate.
118:      * Set this to false if you want to implement custom logic for checking permissions.
119:      */
120: 
121:     'register_permission_check_method' => true,
122: 
123:     /*
124:      * When set to true, Laravel\Octane\Events\OperationTerminated event listener will be registered
125:      * this will refresh permissions on every TickTerminated, TaskTerminated and RequestTerminated
126:      * NOTE: This should not be needed in most cases, but an Octane/Vapor combination benefited from it.
127:      */
128:     'register_octane_reset_listener' => false,
129: 
130:     /*
131:      * Events will fire when a role or permission is assigned/unassigned:
132:      * \Spatie\Permission\Events\RoleAttachedEvent
133:      * \Spatie\Permission\Events\RoleDetachedEvent
134:      * \Spatie\Permission\Events\PermissionAttachedEvent
135:      * \Spatie\Permission\Events\PermissionDetachedEvent
136:      *
137:      * To enable, set to true, and then create listeners to watch these events.
138:      */
139:     'events_enabled' => false,
140: 
141:     /*
142:      * Teams Feature.
143:      * When set to true the package implements teams using the 'team_foreign_key'.
144:      * If you want the migrations to register the 'team_foreign_key', you must
145:      * set this to true before doing the migration.
146:      * If you already did the migration then you must make a new migration to also
147:      * add 'team_foreign_key' to 'roles', 'model_has_roles', and 'model_has_permissions'
148:      * (view the latest version of this package's migration file)
149:      */
150: 
151:     'teams' => false,
152: 
153:     /*
154:      * The class to use to resolve the permissions team id
155:      */
156:     'team_resolver' => DefaultTeamResolver::class,
157: 
158:     /*
159:      * Passport Client Credentials Grant
160:      * When set to true the package will use Passports Client to check permissions
161:      */
162: 
163:     'use_passport_client_credentials' => false,
164: 
165:     /*
166:      * When set to true, the required permission names are added to exception messages.
167:      * This could be considered an information leak in some contexts, so the default
168:      * setting is false here for optimum safety.
169:      */
170: 
171:     'display_permission_in_exception' => false,
172: 
173:     /*
174:      * When set to true, the required role names are added to exception messages.
175:      * This could be considered an information leak in some contexts, so the default
176:      * setting is false here for optimum safety.
177:      */
178: 
179:     'display_role_in_exception' => false,
180: 
181:     /*
182:      * By default wildcard permission lookups are disabled.
183:      * See documentation to understand supported syntax.
184:      */
185: 
186:     'enable_wildcard_permission' => false,
187: 
188:     /*
189:      * The class to use for interpreting wildcard permissions.
190:      * If you need to modify delimiters, override the class and specify its name here.
191:      */
192:     // 'wildcard_permission' => Spatie\Permission\WildcardPermission::class,
193: 
194:     /* Cache-specific settings */
195: 
196:     'cache' => [
197: 
198:         /*
199:          * By default all permissions are cached for 24 hours to speed up performance.
200:          * When permissions or roles are updated the cache is flushed automatically.
201:          */
202: 
203:         'expiration_time' => DateInterval::createFromDateString('24 hours'),
204: 
205:         /*
206:          * The cache key used to store all permissions.
207:          */
208: 
209:         'key' => 'spatie.permission.cache',
210: 
211:         /*
212:          * You may optionally indicate a specific cache driver to use for permission and
213:          * role caching using any of the `store` drivers listed in the cache.php config
214:          * file. Using 'default' here means to use the `default` set in cache.php.
215:          */
216: 
217:         'store' => 'default',
218:     ],
219: ];
```

## File: config/sanctum.php
```php
 1: <?php
 2: 
 3: use Illuminate\Cookie\Middleware\EncryptCookies;
 4: use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
 5: use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
 6: use Laravel\Sanctum\Sanctum;
 7: 
 8: return [
 9: 
10:     /*
11:     |--------------------------------------------------------------------------
12:     | Stateful Domains
13:     |--------------------------------------------------------------------------
14:     |
15:     | Requests from the following domains / hosts will receive stateful API
16:     | authentication cookies. Typically, these should include your local
17:     | and production domains which access your API via a frontend SPA.
18:     |
19:     */
20: 
21:     'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
22:         '%s%s',
23:         'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
24:         Sanctum::currentApplicationUrlWithPort(),
25:         // Sanctum::currentRequestHost(),
26:     ))),
27: 
28:     /*
29:     |--------------------------------------------------------------------------
30:     | Sanctum Guards
31:     |--------------------------------------------------------------------------
32:     |
33:     | This array contains the authentication guards that will be checked when
34:     | Sanctum is trying to authenticate a request. If none of these guards
35:     | are able to authenticate the request, Sanctum will use the bearer
36:     | token that's present on an incoming request for authentication.
37:     |
38:     */
39: 
40:     'guard' => ['web'],
41: 
42:     /*
43:     |--------------------------------------------------------------------------
44:     | Expiration Minutes
45:     |--------------------------------------------------------------------------
46:     |
47:     | This value controls the number of minutes until an issued token will be
48:     | considered expired. This will override any values set in the token's
49:     | "expires_at" attribute, but first-party sessions are not affected.
50:     |
51:     */
52: 
53:     'expiration' => null,
54: 
55:     /*
56:     |--------------------------------------------------------------------------
57:     | Token Prefix
58:     |--------------------------------------------------------------------------
59:     |
60:     | Sanctum can prefix new tokens in order to take advantage of numerous
61:     | security scanning initiatives maintained by open source platforms
62:     | that notify developers if they commit tokens into repositories.
63:     |
64:     | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
65:     |
66:     */
67: 
68:     'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
69: 
70:     /*
71:     |--------------------------------------------------------------------------
72:     | Sanctum Middleware
73:     |--------------------------------------------------------------------------
74:     |
75:     | When authenticating your first-party SPA with Sanctum you may need to
76:     | customize some of the middleware Sanctum uses while processing the
77:     | request. You may change the middleware listed below as required.
78:     |
79:     */
80: 
81:     'middleware' => [
82:         'authenticate_session' => AuthenticateSession::class,
83:         'encrypt_cookies' => EncryptCookies::class,
84:         'validate_csrf_token' => ValidateCsrfToken::class,
85:     ],
86: 
87: ];
```

## File: database/factories/BranchFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Enums\BranchType;
 8: use App\Models\Branch;
 9: use Illuminate\Database\Eloquent\Factories\Factory;
10: 
11: /**
12:  * @extends Factory<Branch>
13:  */
14: class BranchFactory extends Factory
15: {
16:     public function definition(): array
17:     {
18:         return [
19:             'name' => fake()->company(),
20:             'code' => 'BR-'.fake()->unique()->numerify('#####'),
21:             'type' => BranchType::Branch,
22:             'phone' => fake()->phoneNumber(),
23:             'email' => fake()->safeEmail(),
24:             'address' => fake()->streetAddress(),
25:             'city' => fake()->city(),
26:             'is_active' => true,
27:         ];
28:     }
29: }
```

## File: database/factories/LocationFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Enums\LocationType;
 8: use App\Models\Branch;
 9: use App\Models\Location;
10: use Illuminate\Database\Eloquent\Factories\Factory;
11: 
12: /**
13:  * @extends Factory<Location>
14:  */
15: class LocationFactory extends Factory
16: {
17:     public function definition(): array
18:     {
19:         return [
20:             'branch_id' => Branch::factory(),
21:             'name' => fake()->words(2, true),
22:             'code' => 'A-'.fake()->unique()->numerify('##-##'),
23:             'type' => LocationType::Shelf,
24:             'is_active' => true,
25:         ];
26:     }
27: }
```

## File: database/factories/UserFactory.php
```php
 1: <?php
 2: 
 3: namespace Database\Factories;
 4: 
 5: use App\Models\User;
 6: use Illuminate\Database\Eloquent\Factories\Factory;
 7: use Illuminate\Support\Facades\Hash;
 8: use Illuminate\Support\Str;
 9: 
10: /**
11:  * @extends Factory<User>
12:  */
13: class UserFactory extends Factory
14: {
15:     /**
16:      * The current password being used by the factory.
17:      */
18:     protected static ?string $password;
19: 
20:     /**
21:      * Define the model's default state.
22:      *
23:      * @return array<string, mixed>
24:      */
25:     public function definition(): array
26:     {
27:         return [
28:             'name' => fake()->name(),
29:             'email' => fake()->unique()->safeEmail(),
30:             'email_verified_at' => now(),
31:             'password' => static::$password ??= Hash::make('password'),
32:             'remember_token' => Str::random(10),
33:         ];
34:     }
35: 
36:     /**
37:      * Indicate that the model's email address should be unverified.
38:      */
39:     public function unverified(): static
40:     {
41:         return $this->state(fn (array $attributes) => [
42:             'email_verified_at' => null,
43:         ]);
44:     }
45: }
```

## File: database/migrations/0001_01_01_000000_create_users_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('users', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('name');
17:             $table->string('email')->unique();
18:             $table->timestamp('email_verified_at')->nullable();
19:             $table->string('password');
20:             $table->rememberToken();
21:             $table->timestamps();
22:         });
23: 
24:         Schema::create('password_reset_tokens', function (Blueprint $table) {
25:             $table->string('email')->primary();
26:             $table->string('token');
27:             $table->timestamp('created_at')->nullable();
28:         });
29: 
30:         Schema::create('sessions', function (Blueprint $table) {
31:             $table->string('id')->primary();
32:             $table->foreignId('user_id')->nullable()->index();
33:             $table->string('ip_address', 45)->nullable();
34:             $table->text('user_agent')->nullable();
35:             $table->longText('payload');
36:             $table->integer('last_activity')->index();
37:         });
38:     }
39: 
40:     /**
41:      * Reverse the migrations.
42:      */
43:     public function down(): void
44:     {
45:         Schema::dropIfExists('users');
46:         Schema::dropIfExists('password_reset_tokens');
47:         Schema::dropIfExists('sessions');
48:     }
49: };
```

## File: database/migrations/2026_10_14_224514_create_permission_tables.php
```php
  1: <?php
  2: 
  3: use Illuminate\Database\Migrations\Migration;
  4: use Illuminate\Database\Schema\Blueprint;
  5: use Illuminate\Support\Facades\Schema;
  6: 
  7: return new class extends Migration
  8: {
  9:     /**
 10:      * Run the migrations.
 11:      */
 12:     public function up(): void
 13:     {
 14:         $teams = config('permission.teams');
 15:         $tableNames = config('permission.table_names');
 16:         $columnNames = config('permission.column_names');
 17:         $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
 18:         $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
 19: 
 20:         throw_if(empty($tableNames), 'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
 21:         throw_if($teams && empty($columnNames['team_foreign_key'] ?? null), 'Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');
 22: 
 23:         /**
 24:          * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
 25:          */
 26:         Schema::create($tableNames['permissions'], static function (Blueprint $table) {
 27:             $table->id(); // permission id
 28:             $table->string('name');
 29:             $table->string('guard_name');
 30:             $table->timestamps();
 31: 
 32:             $table->unique(['name', 'guard_name']);
 33:         });
 34: 
 35:         /**
 36:          * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
 37:          */
 38:         Schema::create($tableNames['roles'], static function (Blueprint $table) use ($teams, $columnNames) {
 39:             $table->id(); // role id
 40:             if ($teams || config('permission.testing')) { // permission.testing is a fix for sqlite testing
 41:                 $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
 42:                 $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
 43:             }
 44:             $table->string('name');
 45:             $table->string('guard_name');
 46:             $table->timestamps();
 47:             if ($teams || config('permission.testing')) {
 48:                 $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
 49:             } else {
 50:                 $table->unique(['name', 'guard_name']);
 51:             }
 52:         });
 53: 
 54:         Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
 55:             $table->unsignedBigInteger($pivotPermission);
 56: 
 57:             $table->string('model_type');
 58:             $table->unsignedBigInteger($columnNames['model_morph_key']);
 59:             $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');
 60: 
 61:             $table->foreign($pivotPermission)
 62:                 ->references('id') // permission id
 63:                 ->on($tableNames['permissions'])
 64:                 ->cascadeOnDelete();
 65:             if ($teams) {
 66:                 $table->unsignedBigInteger($columnNames['team_foreign_key']);
 67:                 $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');
 68: 
 69:                 $table->primary([$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
 70:                     'model_has_permissions_permission_model_type_primary');
 71:             } else {
 72:                 $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
 73:                     'model_has_permissions_permission_model_type_primary');
 74:             }
 75:         });
 76: 
 77:         Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
 78:             $table->unsignedBigInteger($pivotRole);
 79: 
 80:             $table->string('model_type');
 81:             $table->unsignedBigInteger($columnNames['model_morph_key']);
 82:             $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');
 83: 
 84:             $table->foreign($pivotRole)
 85:                 ->references('id') // role id
 86:                 ->on($tableNames['roles'])
 87:                 ->cascadeOnDelete();
 88:             if ($teams) {
 89:                 $table->unsignedBigInteger($columnNames['team_foreign_key']);
 90:                 $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');
 91: 
 92:                 $table->primary([$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
 93:                     'model_has_roles_role_model_type_primary');
 94:             } else {
 95:                 $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
 96:                     'model_has_roles_role_model_type_primary');
 97:             }
 98:         });
 99: 
100:         Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
101:             $table->unsignedBigInteger($pivotPermission);
102:             $table->unsignedBigInteger($pivotRole);
103: 
104:             $table->foreign($pivotPermission)
105:                 ->references('id') // permission id
106:                 ->on($tableNames['permissions'])
107:                 ->cascadeOnDelete();
108: 
109:             $table->foreign($pivotRole)
110:                 ->references('id') // role id
111:                 ->on($tableNames['roles'])
112:                 ->cascadeOnDelete();
113: 
114:             $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
115:         });
116: 
117:         app('cache')
118:             ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
119:             ->forget(config('permission.cache.key'));
120:     }
121: 
122:     /**
123:      * Reverse the migrations.
124:      */
125:     public function down(): void
126:     {
127:         $tableNames = config('permission.table_names');
128: 
129:         throw_if(empty($tableNames), 'Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
130: 
131:         Schema::dropIfExists($tableNames['role_has_permissions']);
132:         Schema::dropIfExists($tableNames['model_has_roles']);
133:         Schema::dropIfExists($tableNames['model_has_permissions']);
134:         Schema::dropIfExists($tableNames['roles']);
135:         Schema::dropIfExists($tableNames['permissions']);
136:     }
137: };
```

## File: database/migrations/2026_10_14_224514_create_personal_access_tokens_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('personal_access_tokens', function (Blueprint $table) {
15:             $table->id();
16:             $table->morphs('tokenable');
17:             $table->text('name');
18:             $table->string('token', 64)->unique();
19:             $table->text('abilities')->nullable();
20:             $table->timestamp('last_used_at')->nullable();
21:             $table->timestamp('expires_at')->nullable()->index();
22:             $table->timestamps();
23:         });
24:     }
25: 
26:     /**
27:      * Reverse the migrations.
28:      */
29:     public function down(): void
30:     {
31:         Schema::dropIfExists('personal_access_tokens');
32:     }
33: };
```

## File: database/migrations/2026_10_14_225722_create_branches_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('branches', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('name');
17:             $table->timestamps();
18:         });
19:     }
20: 
21:     /**
22:      * Reverse the migrations.
23:      */
24:     public function down(): void
25:     {
26:         Schema::dropIfExists('branches');
27:     }
28: };
```

## File: database/migrations/2026_10_14_230814_add_storage_details_to_branches_and_create_locations_table.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\DB;
 8: use Illuminate\Support\Facades\Schema;
 9: 
10: return new class extends Migration
11: {
12:     public function up(): void
13:     {
14:         Schema::table('branches', function (Blueprint $table): void {
15:             $table->string('code')->nullable();
16:             $table->enum('type', ['branch', 'warehouse'])->default('branch');
17:             $table->string('phone', 50)->nullable();
18:             $table->string('email')->nullable();
19:             $table->string('address')->nullable();
20:             $table->string('city', 120)->nullable();
21:             $table->boolean('is_active')->default(true);
22:         });
23: 
24:         DB::table('branches')->whereNull('code')->orderBy('id')->chunkById(100, function ($branches): void {
25:             foreach ($branches as $branch) {
26:                 DB::table('branches')
27:                     ->where('id', $branch->id)
28:                     ->update(['code' => 'BR-'.str_pad((string) $branch->id, 3, '0', STR_PAD_LEFT)]);
29:             }
30:         });
31: 
32:         Schema::table('branches', function (Blueprint $table): void {
33:             $table->string('code')->nullable(false)->unique()->change();
34:         });
35: 
36:         Schema::create('locations', function (Blueprint $table): void {
37:             $table->id();
38:             $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
39:             $table->string('name');
40:             $table->string('code');
41:             $table->enum('type', ['shelf', 'zone', 'bin', 'damaged', 'receiving'])->default('shelf');
42:             $table->boolean('is_active')->default(true);
43:             $table->timestamps();
44:             $table->unique(['branch_id', 'code']);
45:         });
46:     }
47: 
48:     public function down(): void
49:     {
50:         Schema::dropIfExists('locations');
51: 
52:         Schema::table('branches', function (Blueprint $table): void {
53:             $table->dropUnique('branches_code_unique');
54:             $table->dropColumn(['code', 'type', 'phone', 'email', 'address', 'city', 'is_active']);
55:         });
56:     }
57: };
```

## File: database/migrations/2026_10_15_014600_add_staff_authentication_fields_to_users_table.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::table('users', function (Blueprint $table): void {
14:             $table->string('phone')->nullable()->after('email');
15:             $table->unsignedBigInteger('branch_id')->nullable()->after('password');
16:             $table->boolean('is_active')->default(true)->after('branch_id');
17:         });
18:     }
19: 
20:     public function down(): void
21:     {
22:         Schema::table('users', function (Blueprint $table): void {
23:             $table->dropColumn(['phone', 'branch_id', 'is_active']);
24:         });
25:     }
26: };
```

## File: database/migrations/2026_10_15_021000_create_settings_table.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::create('settings', function (Blueprint $table): void {
14:             $table->id();
15:             $table->string('group')->index();
16:             $table->string('key')->unique();
17:             $table->text('value')->nullable();
18:             $table->string('type', 20)->default('string');
19:             $table->timestamps();
20:         });
21:     }
22: 
23:     public function down(): void
24:     {
25:         Schema::dropIfExists('settings');
26:     }
27: };
```

## File: database/seeders/BranchSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Models\Branch;
 8: use Illuminate\Database\Seeder;
 9: 
10: class BranchSeeder extends Seeder
11: {
12:     public function run(): void
13:     {
14:         if (! app()->environment(['local', 'testing']) || Branch::query()->exists()) {
15:             return;
16:         }
17: 
18:         Branch::factory()->count(3)->create();
19:     }
20: }
```

## File: database/seeders/DatabaseSeeder.php
```php
 1: <?php
 2: 
 3: namespace Database\Seeders;
 4: 
 5: use App\Models\User;
 6: use Illuminate\Database\Console\Seeds\WithoutModelEvents;
 7: use Illuminate\Database\Seeder;
 8: 
 9: class DatabaseSeeder extends Seeder
10: {
11:     use WithoutModelEvents;
12: 
13:     /**
14:      * Seed the application's database.
15:      */
16:     public function run(): void
17:     {
18:         $this->call(SettingSeeder::class);
19:         $this->call(BranchSeeder::class);
20:         $this->call(LocationSeeder::class);
21:         $this->call(RolePermissionSeeder::class);
22: 
23:         // User::factory(10)->create();
24: 
25:         User::factory()->create([
26:             'name' => 'Test User',
27:             'email' => 'test@example.com',
28:         ]);
29:     }
30: }
```

## File: database/seeders/LocationSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Enums\LocationType;
 8: use App\Models\Branch;
 9: use App\Models\Location;
10: use Illuminate\Database\Seeder;
11: 
12: class LocationSeeder extends Seeder
13: {
14:     public function run(): void
15:     {
16:         if (! app()->environment(['local', 'testing']) || Location::query()->exists()) {
17:             return;
18:         }
19: 
20:         Branch::query()->orderBy('id')->each(function (Branch $branch): void {
21:             foreach ([1, 2, 3] as $number) {
22:                 $branch->locations()->create([
23:                     'name' => "Shelf {$number}",
24:                     'code' => sprintf('A-01-%02d', $number),
25:                     'type' => LocationType::Shelf,
26:                     'is_active' => true,
27:                 ]);
28:             }
29:         });
30:     }
31: }
```

## File: database/seeders/RolePermissionSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Enums\StaffRole;
 8: use Illuminate\Database\Seeder;
 9: use Spatie\Permission\Models\Permission;
10: use Spatie\Permission\Models\Role;
11: 
12: class RolePermissionSeeder extends Seeder
13: {
14:     /**
15:      * @var array<string, list<string>>
16:      */
17:     private const PERMISSIONS_BY_MODULE = [
18:         'branches' => ['view', 'create', 'update', 'delete', 'manage'],
19:         'locations' => ['view', 'create', 'update', 'delete', 'manage'],
20:         'users' => ['view', 'create', 'update', 'delete', 'manage'],
21:         'roles' => ['view', 'create', 'update', 'delete', 'manage'],
22:         'permissions' => ['view'],
23:         'customers' => ['view', 'create', 'update', 'delete', 'manage'],
24:         'suppliers' => ['view', 'create', 'update', 'delete', 'manage'],
25:         'products' => ['view', 'create', 'update', 'delete', 'manage'],
26:         'categories' => ['view', 'create', 'update', 'delete', 'manage'],
27:         'inventory' => ['view', 'create', 'update', 'delete', 'manage'],
28:         'warehouses' => ['view', 'create', 'update', 'delete', 'manage'],
29:         'orders' => ['view', 'create', 'update', 'delete', 'manage'],
30:         'sales' => ['view', 'create', 'update', 'delete', 'manage'],
31:         'purchases' => ['view', 'create', 'update', 'delete', 'manage'],
32:         'returns' => ['view', 'create', 'update', 'delete', 'manage'],
33:         'payments' => ['view', 'create', 'update', 'delete', 'manage'],
34:         'shipping' => ['view', 'create', 'update', 'delete', 'manage'],
35:         'transfers' => ['view', 'create', 'update', 'delete', 'manage'],
36:         'reports' => ['view', 'export'],
37:         'notifications' => ['view', 'create', 'update', 'delete', 'manage'],
38:         'pricing' => ['view', 'create', 'update', 'delete', 'manage'],
39:         'settings' => ['view', 'update', 'manage'],
40:         'pos' => ['access', 'sales.view', 'sales.create', 'sales.refund'],
41:     ];
42: 
43:     public function run(): void
44:     {
45:         foreach (self::PERMISSIONS_BY_MODULE as $module => $actions) {
46:             foreach ($actions as $action) {
47:                 Permission::findOrCreate("{$module}.{$action}", 'staff');
48:             }
49:         }
50: 
51:         $superAdmin = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
52:         $superAdmin->syncPermissions(
53:             Permission::query()->where('guard_name', 'staff')->get(),
54:         );
55:     }
56: }
```

## File: database/seeders/SettingSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Models\Setting;
 8: use Illuminate\Database\Seeder;
 9: 
10: class SettingSeeder extends Seeder
11: {
12:     /**
13:      * @var list<array{group: string, key: string, value: string|null, type: string}>
14:      */
15:     private const SETTINGS = [
16:         ['group' => 'company', 'key' => 'company_name', 'value' => 'Alfarid', 'type' => 'string'],
17:         ['group' => 'company', 'key' => 'company_email', 'value' => null, 'type' => 'string'],
18:         ['group' => 'company', 'key' => 'company_phone', 'value' => null, 'type' => 'string'],
19:         ['group' => 'company', 'key' => 'company_address', 'value' => null, 'type' => 'string'],
20:         ['group' => 'company', 'key' => 'company_logo', 'value' => null, 'type' => 'string'],
21:         ['group' => 'company', 'key' => 'commercial_register', 'value' => null, 'type' => 'string'],
22: 
23:         ['group' => 'tax', 'key' => 'tax_enabled', 'value' => '0', 'type' => 'boolean'],
24:         ['group' => 'tax', 'key' => 'tax_number', 'value' => null, 'type' => 'string'],
25:         ['group' => 'tax', 'key' => 'default_tax_rate', 'value' => '14', 'type' => 'float'],
26:         ['group' => 'tax', 'key' => 'prices_include_tax', 'value' => '0', 'type' => 'boolean'],
27: 
28:         ['group' => 'numbering', 'key' => 'invoice_prefix', 'value' => 'INV-', 'type' => 'string'],
29:         ['group' => 'numbering', 'key' => 'invoice_next_number', 'value' => '1000', 'type' => 'integer'],
30:         ['group' => 'numbering', 'key' => 'invoice_padding', 'value' => '6', 'type' => 'integer'],
31:         ['group' => 'numbering', 'key' => 'order_prefix', 'value' => 'ORD-', 'type' => 'string'],
32:         ['group' => 'numbering', 'key' => 'order_next_number', 'value' => '1000', 'type' => 'integer'],
33:         ['group' => 'numbering', 'key' => 'order_padding', 'value' => '6', 'type' => 'integer'],
34:         ['group' => 'numbering', 'key' => 'purchase_order_prefix', 'value' => 'PO-', 'type' => 'string'],
35:         ['group' => 'numbering', 'key' => 'purchase_order_next_number', 'value' => '1000', 'type' => 'integer'],
36:         ['group' => 'numbering', 'key' => 'quotation_prefix', 'value' => 'QT-', 'type' => 'string'],
37:         ['group' => 'numbering', 'key' => 'quotation_next_number', 'value' => '1000', 'type' => 'integer'],
38:     ];
39: 
40:     public function run(): void
41:     {
42:         foreach (self::SETTINGS as $setting) {
43:             Setting::query()->firstOrCreate(
44:                 ['key' => $setting['key']],
45:                 $setting,
46:             );
47:         }
48:     }
49: }
```

## File: routes/api/admin.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use App\Http\Controllers\Api\V1\Admin\AuthController;
 6: use App\Http\Controllers\Api\V1\Admin\BranchController;
 7: use App\Http\Controllers\Api\V1\Admin\LocationController;
 8: use App\Http\Controllers\Api\V1\Admin\PermissionController;
 9: use App\Http\Controllers\Api\V1\Admin\RoleController;
10: use App\Http\Controllers\Api\V1\Admin\SettingController;
11: use App\Http\Controllers\Api\V1\Admin\UserController;
12: use Illuminate\Support\Facades\Route;
13: 
14: // Public admin authentication endpoint.
15: Route::post('auth/login', [AuthController::class, 'login'])
16:     ->middleware('throttle:5,1')
17:     ->name('admin.auth.login');
18: 
19: // Staff-only API endpoints: require a Sanctum token and an active staff account.
20: Route::middleware(['auth:sanctum', 'auth.staff'])
21:     ->name('admin.')
22:     ->group(function (): void {
23:         Route::get('me', [AuthController::class, 'me'])->name('auth.me');
24: 
25:         Route::apiResource('branches', BranchController::class)
26:             ->only(['index', 'show'])
27:             ->middleware('permission:branches.view|branches.manage');
28:         Route::apiResource('branches', BranchController::class)
29:             ->only(['store'])
30:             ->middleware('permission:branches.create|branches.manage');
31:         Route::apiResource('branches', BranchController::class)
32:             ->only(['update'])
33:             ->middleware('permission:branches.update|branches.manage');
34:         Route::apiResource('branches', BranchController::class)
35:             ->only(['destroy'])
36:             ->middleware('permission:branches.delete|branches.manage');
37: 
38:         Route::scopeBindings()->group(function (): void {
39:             Route::apiResource('branches.locations', LocationController::class)
40:                 ->only(['index', 'show'])
41:                 ->middleware('permission:locations.view|locations.manage');
42:             Route::apiResource('branches.locations', LocationController::class)
43:                 ->only(['store'])
44:                 ->middleware('permission:locations.create|locations.manage');
45:             Route::apiResource('branches.locations', LocationController::class)
46:                 ->only(['update'])
47:                 ->middleware('permission:locations.update|locations.manage');
48:             Route::apiResource('branches.locations', LocationController::class)
49:                 ->only(['destroy'])
50:                 ->middleware('permission:locations.delete|locations.manage');
51:         });
52: 
53:         Route::apiResource('users', UserController::class)
54:             ->only(['index', 'show'])
55:             ->middleware('permission:users.view|users.manage');
56:         Route::apiResource('users', UserController::class)
57:             ->only(['store'])
58:             ->middleware('permission:users.create|users.manage');
59:         Route::apiResource('users', UserController::class)
60:             ->only(['update'])
61:             ->middleware('permission:users.update|users.manage');
62:         Route::apiResource('users', UserController::class)
63:             ->only(['destroy'])
64:             ->middleware('permission:users.delete|users.manage');
65: 
66:         Route::apiResource('roles', RoleController::class)
67:             ->only(['index', 'show'])
68:             ->middleware('permission:roles.view|roles.manage');
69:         Route::apiResource('roles', RoleController::class)
70:             ->only(['store'])
71:             ->middleware('permission:roles.create|roles.manage');
72:         Route::apiResource('roles', RoleController::class)
73:             ->only(['update'])
74:             ->middleware('permission:roles.update|roles.manage');
75:         Route::apiResource('roles', RoleController::class)
76:             ->only(['destroy'])
77:             ->middleware('permission:roles.delete|roles.manage');
78: 
79:         Route::get('permissions', [PermissionController::class, 'index'])
80:             ->middleware('permission:permissions.view|roles.manage')
81:             ->name('permissions.index');
82: 
83:         Route::get('settings', [SettingController::class, 'index'])
84:             ->middleware('permission:settings.view|settings.manage')
85:             ->name('settings.index');
86:         Route::put('settings', [SettingController::class, 'update'])
87:             ->middleware('permission:settings.update|settings.manage')
88:             ->name('settings.update');
89:     });
```

## File: tests/Feature/AdminAuthEndpointsTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature;
  6: 
  7: use App\Models\Branch;
  8: use App\Models\Customer;
  9: use App\Models\User;
 10: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 11: use Illuminate\Support\Facades\Auth;
 12: use Illuminate\Support\Facades\Hash;
 13: use Illuminate\Testing\TestResponse;
 14: use Spatie\Permission\Models\Permission;
 15: use Spatie\Permission\Models\Role;
 16: use Tests\TestCase;
 17: 
 18: class AdminAuthEndpointsTest extends TestCase
 19: {
 20:     use LazilyRefreshDatabase;
 21: 
 22:     public function test_login_issues_staff_token_and_me_returns_branch_roles_and_permissions(): void
 23:     {
 24:         $branch = Branch::factory()->create();
 25:         $permission = Permission::create(['name' => 'users.view', 'guard_name' => 'staff']);
 26:         $role = Role::create(['name' => 'Manager', 'guard_name' => 'staff']);
 27:         $role->givePermissionTo($permission);
 28:         $staff = User::factory()->create([
 29:             'name' => 'Admin User',
 30:             'email' => 'admin@example.test',
 31:             'password' => 'secret-password',
 32:             'branch_id' => $branch->id,
 33:             'is_active' => true,
 34:         ]);
 35:         $staff->assignRole($role);
 36: 
 37:         $response = $this->postJson('/api/v1/admin/auth/login', [
 38:             'email' => 'admin@example.test',
 39:             'password' => 'secret-password',
 40:         ]);
 41: 
 42:         $response->assertOk()
 43:             ->assertJsonPath('data.email', 'admin@example.test')
 44:             ->assertJsonPath('data.branch.id', $branch->id)
 45:             ->assertJsonPath('data.roles.0.name', 'Manager')
 46:             ->assertJsonPath('data.permissions.0', 'users.view')
 47:             ->assertJsonStructure(['data' => ['id', 'name', 'email', 'branch', 'roles', 'permissions'], 'token']);
 48: 
 49:         $token = $response->json('token');
 50:         $this->getAsToken($token, '/api/v1/admin/me')
 51:             ->assertOk()
 52:             ->assertJsonPath('data.id', $staff->id)
 53:             ->assertJsonPath('data.branch.name', $branch->name);
 54: 
 55:         $this->assertTrue(Hash::check('secret-password', $staff->refresh()->password));
 56:     }
 57: 
 58:     public function test_login_uses_only_staff_users_and_rejects_inactive_or_invalid_accounts(): void
 59:     {
 60:         Customer::create([
 61:             'name' => 'Customer with shared credentials',
 62:             'email' => 'shared@example.test',
 63:             'password' => 'secret-password',
 64:         ]);
 65: 
 66:         $this->postJson('/api/v1/admin/auth/login', [
 67:             'email' => 'shared@example.test',
 68:             'password' => 'secret-password',
 69:         ])->assertUnauthorized();
 70: 
 71:         User::factory()->create([
 72:             'email' => 'inactive@example.test',
 73:             'password' => 'secret-password',
 74:             'is_active' => false,
 75:         ]);
 76: 
 77:         $this->postJson('/api/v1/admin/auth/login', [
 78:             'email' => 'inactive@example.test',
 79:             'password' => 'secret-password',
 80:         ])->assertUnauthorized();
 81: 
 82:         $this->postJson('/api/v1/admin/auth/login', [
 83:             'email' => 'inactive@example.test',
 84:             'password' => 'wrong-password',
 85:         ])->assertUnauthorized();
 86:     }
 87: 
 88:     public function test_login_validates_required_email_and_password(): void
 89:     {
 90:         $this->postJson('/api/v1/admin/auth/login', [
 91:             'email' => 'not-an-email',
 92:         ])->assertUnprocessable()
 93:             ->assertJsonValidationErrors(['email', 'password']);
 94:     }
 95: 
 96:     public function test_me_returns_401_without_a_staff_token(): void
 97:     {
 98:         Auth::forgetGuards();
 99: 
100:         $this->getJson('/api/v1/admin/me')->assertUnauthorized();
101:     }
102: 
103:     private function getAsToken(string $token, string $uri): TestResponse
104:     {
105:         Auth::forgetGuards();
106: 
107:         return $this->withToken($token)->getJson($uri);
108:     }
109: }
```

## File: tests/Feature/AdminRouteAggregationTest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Tests\Feature;
 6: 
 7: use Illuminate\Support\Facades\Route;
 8: use Tests\TestCase;
 9: 
10: class AdminRouteAggregationTest extends TestCase
11: {
12:     public function test_admin_routes_are_loaded_with_expected_names_and_security_middleware(): void
13:     {
14:         $routes = Route::getRoutes();
15: 
16:         foreach ([
17:             'admin.auth.login',
18:             'admin.auth.me',
19:             'admin.branches.index',
20:             'admin.branches.locations.index',
21:             'admin.users.index',
22:             'admin.roles.index',
23:             'admin.permissions.index',
24:             'admin.settings.index',
25:             'admin.settings.update',
26:         ] as $routeName) {
27:             $this->assertNotNull($routes->getByName($routeName), "Expected [{$routeName}] to be registered.");
28:         }
29: 
30:         $login = $routes->getByName('admin.auth.login');
31:         $this->assertContains('throttle:5,1', $login->gatherMiddleware());
32:         $this->assertNotContains('auth:sanctum', $login->gatherMiddleware());
33: 
34:         foreach ([
35:             'admin.auth.me',
36:             'admin.branches.index',
37:             'admin.branches.locations.index',
38:             'admin.users.index',
39:             'admin.roles.index',
40:             'admin.permissions.index',
41:             'admin.settings.index',
42:             'admin.settings.update',
43:         ] as $routeName) {
44:             $middleware = $routes->getByName($routeName)->gatherMiddleware();
45:             $this->assertContains('auth:sanctum', $middleware, "Expected [{$routeName}] to require a Sanctum token.");
46:             $this->assertContains('auth.staff', $middleware, "Expected [{$routeName}] to require staff authentication.");
47:         }
48: 
49:         $this->assertContains(
50:             'permission:settings.update|settings.manage',
51:             $routes->getByName('admin.settings.update')->gatherMiddleware(),
52:         );
53:     }
54: }
```

## File: tests/Feature/AdminSettingsEndpointsTest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Tests\Feature;
 6: 
 7: use App\Models\User;
 8: use App\Services\SettingService;
 9: use Database\Seeders\SettingSeeder;
10: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
11: use Illuminate\Support\Facades\Auth;
12: use Spatie\Permission\Models\Permission;
13: use Tests\TestCase;
14: 
15: class AdminSettingsEndpointsTest extends TestCase
16: {
17:     use LazilyRefreshDatabase;
18: 
19:     public function test_admin_can_read_grouped_settings_with_typed_values(): void
20:     {
21:         $this->seedSettings();
22:         $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;
23: 
24:         $this->requestWithToken($token, 'GET', '/api/v1/admin/settings')
25:             ->assertOk()
26:             ->assertJsonPath('data.tax.tax_enabled', false)
27:             ->assertJsonPath('data.tax.default_tax_rate', 14)
28:             ->assertJsonPath('data.numbering.invoice_next_number', 1000)
29:             ->assertJsonPath('data.company.company_name', 'Alfarid');
30:     }
31: 
32:     public function test_admin_can_update_settings_and_cache_is_invalidated(): void
33:     {
34:         $this->seedSettings();
35:         $service = app(SettingService::class);
36:         $this->assertSame(14.0, $service->get('default_tax_rate'));
37:         $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;
38: 
39:         $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
40:             'company_name' => 'Alfarid Trading',
41:             'tax_enabled' => true,
42:             'default_tax_rate' => 15,
43:             'order_next_number' => 2000,
44:         ])->assertOk()
45:             ->assertJsonPath('data.company.company_name', 'Alfarid Trading')
46:             ->assertJsonPath('data.tax.tax_enabled', true)
47:             ->assertJsonPath('data.tax.default_tax_rate', 15)
48:             ->assertJsonPath('data.numbering.order_next_number', 2000);
49: 
50:         $this->assertSame('Alfarid Trading', $service->get('company_name'));
51:         $this->assertDatabaseHas('settings', ['key' => 'tax_enabled', 'value' => '1']);
52:     }
53: 
54:     public function test_update_validates_tax_rates_counters_and_unknown_keys(): void
55:     {
56:         $this->seedSettings();
57:         $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;
58: 
59:         $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
60:             'default_tax_rate' => 101,
61:             'invoice_next_number' => 0,
62:             'unknown_option' => 'value',
63:         ])->assertUnprocessable()
64:             ->assertJsonValidationErrors(['default_tax_rate', 'invoice_next_number', 'unknown_option']);
65:     }
66: 
67:     public function test_number_generation_increments_the_counter_and_applies_padding(): void
68:     {
69:         $this->seedSettings();
70:         $service = app(SettingService::class);
71: 
72:         $this->assertSame('ORD-001000', $service->generateNextNumber('order'));
73:         $this->assertSame('ORD-001001', $service->generateNextNumber('order'));
74:         $this->assertSame(1002, $service->get('order_next_number'));
75:         $this->assertSame('PO-1000', $service->generateNextNumber('purchase_order'));
76:     }
77: 
78:     private function seedSettings(): void
79:     {
80:         $this->seed(SettingSeeder::class);
81:     }
82: 
83:     private function staffWithSettingsPermission(): User
84:     {
85:         $permission = Permission::findOrCreate('settings.manage', 'staff');
86:         $staff = User::factory()->create();
87:         $staff->givePermissionTo($permission);
88: 
89:         return $staff;
90:     }
91: 
92:     /** @param array<string, mixed> $payload */
93:     private function requestWithToken(string $token, string $method, string $uri, array $payload = [])
94:     {
95:         Auth::forgetGuards();
96: 
97:         return $this->withToken($token)->json($method, $uri, $payload);
98:     }
99: }
```

## File: tests/Feature/AdminUserEndpointsTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature;
  6: 
  7: use App\Enums\StaffRole;
  8: use App\Models\Branch;
  9: use App\Models\User;
 10: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 11: use Illuminate\Support\Facades\Auth;
 12: use Illuminate\Support\Facades\Hash;
 13: use Illuminate\Testing\TestResponse;
 14: use Spatie\Permission\Models\Permission;
 15: use Spatie\Permission\Models\Role;
 16: use Tests\TestCase;
 17: 
 18: class AdminUserEndpointsTest extends TestCase
 19: {
 20:     use LazilyRefreshDatabase;
 21: 
 22:     public function test_user_index_forbids_an_authenticated_staff_member_without_permission(): void
 23:     {
 24:         $staff = User::factory()->create();
 25:         $token = $staff->createToken('test')->plainTextToken;
 26: 
 27:         $this->requestWithToken($token, 'GET', '/api/v1/admin/users')->assertForbidden();
 28:     }
 29: 
 30:     public function test_user_crud_hashes_password_and_syncs_roles(): void
 31:     {
 32:         $branch = Branch::factory()->create();
 33:         $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'staff']);
 34:         $manager = $this->staffWithPermission('users.manage');
 35:         $token = $manager->createToken('test')->plainTextToken;
 36: 
 37:         $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/users', [
 38:             'name' => 'New Cashier',
 39:             'email' => 'cashier@example.test',
 40:             'phone' => '+201001234567',
 41:             'password' => 'long-password',
 42:             'branch_id' => $branch->id,
 43:             'roles' => ['Cashier'],
 44:         ]);
 45:         $created->assertCreated()
 46:             ->assertJsonPath('data.name', 'New Cashier')
 47:             ->assertJsonPath('data.branch.id', $branch->id)
 48:             ->assertJsonPath('data.roles.0.name', 'Cashier');
 49: 
 50:         $userId = $created->json('data.id');
 51:         $user = User::findOrFail($userId);
 52:         $this->assertTrue(Hash::check('long-password', $user->password));
 53:         $this->assertTrue($user->hasRole($cashierRole, 'staff'));
 54: 
 55:         $this->requestWithToken($token, 'PATCH', "/api/v1/admin/users/{$userId}", [
 56:             'name' => 'Updated Cashier',
 57:             'email' => 'cashier@example.test',
 58:             'branch_id' => null,
 59:             'roles' => [],
 60:         ])->assertOk()
 61:             ->assertJsonPath('data.name', 'Updated Cashier')
 62:             ->assertJsonPath('data.branch', null)
 63:             ->assertJsonPath('data.roles', []);
 64: 
 65:         $this->requestWithToken($token, 'GET', "/api/v1/admin/users/{$userId}")
 66:             ->assertOk()
 67:             ->assertJsonPath('data.email', 'cashier@example.test');
 68:     }
 69: 
 70:     public function test_user_index_filters_by_branch_and_staff_role_with_pagination(): void
 71:     {
 72:         $branch = Branch::factory()->create();
 73:         $otherBranch = Branch::factory()->create();
 74:         $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'staff']);
 75:         $admin = $this->staffWithPermission('users.view');
 76:         $token = $admin->createToken('test')->plainTextToken;
 77: 
 78:         $matchingUser = User::factory()->create(['branch_id' => $branch->id]);
 79:         $matchingUser->assignRole($cashierRole);
 80:         User::factory()->create(['branch_id' => $branch->id]);
 81:         User::factory()->create(['branch_id' => $otherBranch->id])->assignRole($cashierRole);
 82: 
 83:         $this->requestWithToken(
 84:             $token,
 85:             'GET',
 86:             "/api/v1/admin/users?branch_id={$branch->id}&role=Cashier&per_page=1",
 87:         )->assertOk()
 88:             ->assertJsonCount(1, 'data')
 89:             ->assertJsonPath('data.0.id', $matchingUser->id)
 90:             ->assertJsonPath('meta.per_page', 1);
 91:     }
 92: 
 93:     public function test_user_validation_rejects_duplicate_email_unknown_role_and_branch(): void
 94:     {
 95:         User::factory()->create(['email' => 'existing@example.test']);
 96:         $manager = $this->staffWithPermission('users.manage');
 97:         $token = $manager->createToken('test')->plainTextToken;
 98: 
 99:         $this->requestWithToken($token, 'POST', '/api/v1/admin/users', [
100:             'name' => 'Invalid User',
101:             'email' => 'existing@example.test',
102:             'password' => 'long-password',
103:             'branch_id' => 999,
104:             'roles' => ['missing-role'],
105:         ])->assertUnprocessable()
106:             ->assertJsonValidationErrors(['email', 'branch_id', 'roles.0']);
107:     }
108: 
109:     public function test_destroy_deactivates_user_and_revokes_tokens(): void
110:     {
111:         $manager = $this->staffWithPermission('users.manage');
112:         $adminToken = $manager->createToken('test')->plainTextToken;
113:         $target = User::factory()->create();
114:         $targetToken = $target->createToken('other-device')->plainTextToken;
115: 
116:         $this->requestWithToken($adminToken, 'DELETE', "/api/v1/admin/users/{$target->id}")
117:             ->assertOk()
118:             ->assertJsonPath('data.is_active', false);
119: 
120:         $this->assertFalse($target->refresh()->is_active);
121:         $this->assertDatabaseMissing('personal_access_tokens', [
122:             'tokenable_type' => User::class,
123:             'tokenable_id' => $target->id,
124:         ]);
125: 
126:         $this->requestWithToken($targetToken, 'GET', '/api/v1/admin/me')->assertUnauthorized();
127:     }
128: 
129:     public function test_destroy_forbids_self_and_super_admin_accounts(): void
130:     {
131:         $manager = $this->staffWithPermission('users.manage');
132:         $adminToken = $manager->createToken('test')->plainTextToken;
133: 
134:         $this->requestWithToken($adminToken, 'DELETE', "/api/v1/admin/users/{$manager->id}")
135:             ->assertForbidden();
136: 
137:         $superAdminRole = Role::create([
138:             'name' => StaffRole::SuperAdmin->value,
139:             'guard_name' => 'staff',
140:         ]);
141:         $superAdmin = User::factory()->create();
142:         $superAdmin->assignRole($superAdminRole);
143: 
144:         $this->requestWithToken($adminToken, 'DELETE', "/api/v1/admin/users/{$superAdmin->id}")
145:             ->assertForbidden();
146: 
147:         $this->requestWithToken($adminToken, 'PATCH', "/api/v1/admin/users/{$superAdmin->id}", [
148:             'is_active' => false,
149:             'roles' => [],
150:         ])->assertForbidden();
151: 
152:         $this->assertTrue($superAdmin->refresh()->is_active);
153:     }
154: 
155:     private function staffWithPermission(string $permissionName): User
156:     {
157:         $permission = Permission::findOrCreate($permissionName, 'staff');
158:         $staff = User::factory()->create();
159:         $staff->givePermissionTo($permission);
160: 
161:         return $staff;
162:     }
163: 
164:     /**
165:      * @param  array<string, mixed>  $payload
166:      */
167:     private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
168:     {
169:         Auth::forgetGuards();
170: 
171:         return $this->withToken($token)->json($method, $uri, $payload);
172:     }
173: }
```

## File: tests/Feature/BranchEndpointsTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature;
  6: 
  7: use App\Models\Branch;
  8: use App\Models\User;
  9: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 10: use Illuminate\Support\Facades\Auth;
 11: use Illuminate\Support\Facades\DB;
 12: use Illuminate\Support\Facades\Schema;
 13: use Illuminate\Database\Schema\Blueprint;
 14: use Illuminate\Testing\TestResponse;
 15: use Spatie\Permission\Models\Permission;
 16: use Tests\TestCase;
 17: 
 18: class BranchEndpointsTest extends TestCase
 19: {
 20:     use LazilyRefreshDatabase;
 21: 
 22:     public function test_branch_crud_returns_validated_branch_data_and_pagination(): void
 23:     {
 24:         $admin = $this->staffWithPermission('branches.manage');
 25:         $token = $admin->createToken('test')->plainTextToken;
 26: 
 27:         $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/branches', [
 28:             'name' => 'Main Store',
 29:             'code' => 'BR-001',
 30:             'type' => 'branch',
 31:             'phone' => '+201001234567',
 32:             'email' => 'main@example.test',
 33:             'address' => '1 Market Street',
 34:             'city' => 'Cairo',
 35:             'is_active' => true,
 36:         ]);
 37: 
 38:         $created->assertCreated()
 39:             ->assertJsonPath('data.name', 'Main Store')
 40:             ->assertJsonPath('data.code', 'BR-001')
 41:             ->assertJsonPath('data.type', 'branch')
 42:             ->assertJsonPath('data.is_active', true);
 43: 
 44:         $branchId = $created->json('data.id');
 45: 
 46:         $this->requestWithToken($token, 'PATCH', "/api/v1/admin/branches/{$branchId}", [
 47:             'name' => 'Central Warehouse',
 48:             'type' => 'warehouse',
 49:         ])->assertOk()
 50:             ->assertJsonPath('data.name', 'Central Warehouse')
 51:             ->assertJsonPath('data.type', 'warehouse');
 52: 
 53:         $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branchId}")
 54:             ->assertOk()
 55:             ->assertJsonPath('data.code', 'BR-001');
 56: 
 57:         $this->requestWithToken($token, 'GET', '/api/v1/admin/branches?per_page=1')
 58:             ->assertOk()
 59:             ->assertJsonCount(1, 'data')
 60:             ->assertJsonPath('meta.per_page', 1);
 61:     }
 62: 
 63:     public function test_branch_validation_rejects_duplicate_codes_and_unknown_types(): void
 64:     {
 65:         Branch::factory()->create(['code' => 'BR-050']);
 66:         $admin = $this->staffWithPermission('branches.manage');
 67:         $token = $admin->createToken('test')->plainTextToken;
 68: 
 69:         $this->requestWithToken($token, 'POST', '/api/v1/admin/branches', [
 70:             'name' => 'Invalid Branch',
 71:             'code' => 'BR-050',
 72:             'type' => 'shop',
 73:         ])->assertUnprocessable()
 74:             ->assertJsonValidationErrors(['code', 'type']);
 75:     }
 76: 
 77:     public function test_branch_cannot_be_deleted_while_staff_are_assigned(): void
 78:     {
 79:         $branch = Branch::factory()->create();
 80:         User::factory()->create(['branch_id' => $branch->id]);
 81:         $admin = $this->staffWithPermission('branches.manage');
 82:         $token = $admin->createToken('test')->plainTextToken;
 83: 
 84:         $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}")
 85:             ->assertStatus(409);
 86: 
 87:         $this->assertDatabaseHas('branches', ['id' => $branch->id]);
 88:     }
 89: 
 90:     public function test_branch_cannot_be_deleted_while_stock_movements_reference_it(): void
 91:     {
 92:         Schema::create('stock_movements', function (Blueprint $table): void {
 93:             $table->id();
 94:             $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
 95:         });
 96: 
 97:         $branch = Branch::factory()->create();
 98:         DB::table('stock_movements')->insert(['branch_id' => $branch->id]);
 99:         $admin = $this->staffWithPermission('branches.manage');
100:         $token = $admin->createToken('test')->plainTextToken;
101: 
102:         $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}")
103:             ->assertStatus(409);
104: 
105:         $this->assertDatabaseHas('branches', ['id' => $branch->id]);
106:         $this->assertDatabaseHas('stock_movements', ['branch_id' => $branch->id]);
107:     }
108: 
109:     public function test_deleting_an_unreferenced_branch_cascades_to_its_locations(): void
110:     {
111:         $branch = Branch::factory()->create();
112:         $location = $branch->locations()->create([
113:             'name' => 'Rack A',
114:             'code' => 'A-01-01',
115:             'type' => 'shelf',
116:         ]);
117:         $admin = $this->staffWithPermission('branches.manage');
118:         $token = $admin->createToken('test')->plainTextToken;
119: 
120:         $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}")
121:             ->assertNoContent();
122: 
123:         $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
124:         $this->assertDatabaseMissing('locations', ['id' => $location->id]);
125:     }
126: 
127:     private function staffWithPermission(string $permissionName): User
128:     {
129:         $permission = Permission::findOrCreate($permissionName, 'staff');
130:         $staff = User::factory()->create();
131:         $staff->givePermissionTo($permission);
132: 
133:         return $staff;
134:     }
135: 
136:     /**
137:      * @param array<string, mixed> $payload
138:      */
139:     private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
140:     {
141:         Auth::forgetGuards();
142: 
143:         return $this->withToken($token)->json($method, $uri, $payload);
144:     }
145: }
```

## File: tests/Feature/LocationEndpointsTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature;
  6: 
  7: use App\Models\Branch;
  8: use App\Models\Location;
  9: use App\Models\User;
 10: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 11: use Illuminate\Support\Facades\Auth;
 12: use Illuminate\Testing\TestResponse;
 13: use Spatie\Permission\Models\Permission;
 14: use Tests\TestCase;
 15: 
 16: class LocationEndpointsTest extends TestCase
 17: {
 18:     use LazilyRefreshDatabase;
 19: 
 20:     public function test_location_crud_is_scoped_to_the_parent_branch(): void
 21:     {
 22:         $branch = Branch::factory()->create();
 23:         $otherBranch = Branch::factory()->create();
 24:         $admin = $this->staffWithPermission('locations.manage');
 25:         $token = $admin->createToken('test')->plainTextToken;
 26: 
 27:         $created = $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", [
 28:             'name' => 'Aisle 1 Bin 2',
 29:             'code' => 'A-01-02',
 30:             'type' => 'bin',
 31:             'is_active' => true,
 32:             'branch_id' => $otherBranch->id,
 33:         ]);
 34: 
 35:         $created->assertCreated()
 36:             ->assertJsonPath('data.name', 'Aisle 1 Bin 2')
 37:             ->assertJsonPath('data.branch_id', $branch->id)
 38:             ->assertJsonPath('data.type', 'bin');
 39: 
 40:         $locationId = $created->json('data.id');
 41:         $otherBranchLocationUri = "/api/v1/admin/branches/{$otherBranch->id}/locations/{$locationId}";
 42: 
 43:         $this->requestWithToken($token, 'GET', $otherBranchLocationUri)->assertNotFound();
 44:         $this->requestWithToken($token, 'PATCH', $otherBranchLocationUri, ['name' => 'Wrong Branch'])->assertNotFound();
 45:         $this->requestWithToken($token, 'DELETE', $otherBranchLocationUri)->assertNotFound();
 46: 
 47:         $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branch->id}/locations?per_page=1")
 48:             ->assertOk()
 49:             ->assertJsonCount(1, 'data')
 50:             ->assertJsonPath('data.0.id', $locationId)
 51:             ->assertJsonPath('meta.per_page', 1);
 52: 
 53:         $this->requestWithToken($token, 'PATCH', "/api/v1/admin/branches/{$branch->id}/locations/{$locationId}", [
 54:             'name' => 'Updated Bin',
 55:         ])->assertOk()->assertJsonPath('data.name', 'Updated Bin');
 56: 
 57:         $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}/locations/{$locationId}")
 58:             ->assertNoContent();
 59: 
 60:         $this->assertDatabaseMissing('locations', ['id' => $locationId]);
 61:     }
 62: 
 63:     public function test_location_codes_are_unique_within_a_branch_but_may_repeat_in_another(): void
 64:     {
 65:         $branch = Branch::factory()->create();
 66:         $otherBranch = Branch::factory()->create();
 67:         $admin = $this->staffWithPermission('locations.manage');
 68:         $token = $admin->createToken('test')->plainTextToken;
 69:         $payload = [
 70:             'name' => 'Rack A',
 71:             'code' => 'A-01-01',
 72:             'type' => 'shelf',
 73:         ];
 74: 
 75:         $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", $payload)
 76:             ->assertCreated();
 77: 
 78:         $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", $payload)
 79:             ->assertUnprocessable()
 80:             ->assertJsonValidationErrors(['code']);
 81: 
 82:         $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$otherBranch->id}/locations", $payload)
 83:             ->assertCreated();
 84:     }
 85: 
 86:     public function test_location_endpoints_forbid_staff_without_location_permissions(): void
 87:     {
 88:         $branch = Branch::factory()->create();
 89:         $location = $branch->locations()->create([
 90:             'name' => 'Receiving Area',
 91:             'code' => 'R-01',
 92:             'type' => 'receiving',
 93:         ]);
 94:         $staff = User::factory()->create();
 95:         $token = $staff->createToken('test')->plainTextToken;
 96: 
 97:         $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branch->id}/locations")
 98:             ->assertForbidden();
 99:         $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branch->id}/locations/{$location->id}")
100:             ->assertForbidden();
101:     }
102: 
103:     private function staffWithPermission(string $permissionName): User
104:     {
105:         $permission = Permission::findOrCreate($permissionName, 'staff');
106:         $staff = User::factory()->create();
107:         $staff->givePermissionTo($permission);
108: 
109:         return $staff;
110:     }
111: 
112:     /**
113:      * @param array<string, mixed> $payload
114:      */
115:     private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
116:     {
117:         Auth::forgetGuards();
118: 
119:         return $this->withToken($token)->json($method, $uri, $payload);
120:     }
121: }
```

## File: tests/Feature/RolePermissionEndpointsTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature;
  6: 
  7: use App\Enums\StaffRole;
  8: use App\Models\User;
  9: use Database\Seeders\RolePermissionSeeder;
 10: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 11: use Illuminate\Support\Facades\Auth;
 12: use Illuminate\Testing\TestResponse;
 13: use Spatie\Permission\Models\Permission;
 14: use Spatie\Permission\Models\Role;
 15: use Tests\TestCase;
 16: 
 17: class RolePermissionEndpointsTest extends TestCase
 18: {
 19:     use LazilyRefreshDatabase;
 20: 
 21:     public function test_roles_index_forbids_staff_without_role_permissions(): void
 22:     {
 23:         $staff = User::factory()->create();
 24:         $token = $staff->createToken('test')->plainTextToken;
 25: 
 26:         $this->requestWithToken($token, 'GET', '/api/v1/admin/roles')->assertForbidden();
 27:     }
 28: 
 29:     public function test_permissions_are_grouped_by_prefix_for_staff(): void
 30:     {
 31:         Permission::create(['name' => 'inventory.view', 'guard_name' => 'staff']);
 32:         Permission::create(['name' => 'users.view', 'guard_name' => 'staff']);
 33:         Permission::create(['name' => 'web.only', 'guard_name' => 'web']);
 34:         $admin = $this->staffWithPermission('permissions.view');
 35:         $token = $admin->createToken('test')->plainTextToken;
 36: 
 37:         $this->requestWithToken($token, 'GET', '/api/v1/admin/permissions')
 38:             ->assertOk()
 39:             ->assertJsonPath('data.inventory.0.name', 'inventory.view')
 40:             ->assertJsonPath('data.users.0.name', 'users.view')
 41:             ->assertJsonMissing(['name' => 'web.only']);
 42:     }
 43: 
 44:     public function test_role_crud_creates_updates_and_deletes_staff_roles(): void
 45:     {
 46:         Permission::create(['name' => 'users.view', 'guard_name' => 'staff']);
 47:         Permission::create(['name' => 'users.create', 'guard_name' => 'staff']);
 48:         $admin = $this->staffWithPermission('roles.manage');
 49:         $token = $admin->createToken('test')->plainTextToken;
 50: 
 51:         $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/roles', [
 52:             'name' => 'Operator',
 53:             'permissions' => ['users.view'],
 54:         ]);
 55:         $created->assertCreated()
 56:             ->assertJsonPath('data.guard_name', 'staff')
 57:             ->assertJsonPath('data.permissions.0', 'users.view');
 58: 
 59:         $roleId = $created->json('data.id');
 60:         $this->requestWithToken($token, 'PATCH', "/api/v1/admin/roles/{$roleId}", [
 61:             'name' => 'Senior Operator',
 62:             'permissions' => ['users.create'],
 63:         ])->assertOk()
 64:             ->assertJsonPath('data.name', 'Senior Operator')
 65:             ->assertJsonPath('data.permissions.0', 'users.create');
 66: 
 67:         $this->requestWithToken($token, 'GET', "/api/v1/admin/roles/{$roleId}")
 68:             ->assertOk()
 69:             ->assertJsonPath('data.name', 'Senior Operator');
 70: 
 71:         $this->requestWithToken($token, 'DELETE', "/api/v1/admin/roles/{$roleId}")
 72:             ->assertNoContent();
 73: 
 74:         $this->assertDatabaseMissing('roles', ['id' => $roleId]);
 75:     }
 76: 
 77:     public function test_role_permissions_reject_permissions_from_another_guard(): void
 78:     {
 79:         Permission::create(['name' => 'web.only', 'guard_name' => 'web']);
 80:         $admin = $this->staffWithPermission('roles.manage');
 81:         $token = $admin->createToken('test')->plainTextToken;
 82: 
 83:         $this->requestWithToken($token, 'POST', '/api/v1/admin/roles', [
 84:             'name' => 'Invalid Role',
 85:             'permissions' => ['web.only'],
 86:         ])->assertUnprocessable()
 87:             ->assertJsonValidationErrors(['permissions.0']);
 88:     }
 89: 
 90:     public function test_super_admin_role_cannot_be_deleted(): void
 91:     {
 92:         $admin = $this->staffWithPermission('roles.manage');
 93:         $superAdmin = Role::create([
 94:             'name' => StaffRole::SuperAdmin->value,
 95:             'guard_name' => 'staff',
 96:         ]);
 97:         $token = $admin->createToken('test')->plainTextToken;
 98: 
 99:         $this->requestWithToken($token, 'DELETE', "/api/v1/admin/roles/{$superAdmin->id}")
100:             ->assertForbidden();
101: 
102:         $this->requestWithToken($token, 'PATCH', "/api/v1/admin/roles/{$superAdmin->id}", [
103:             'name' => 'Renamed Root',
104:         ])->assertForbidden();
105: 
106:         $this->assertDatabaseHas('roles', ['id' => $superAdmin->id]);
107:     }
108: 
109:     public function test_seeder_creates_an_idempotent_super_admin_with_every_staff_permission(): void
110:     {
111:         $this->seed(RolePermissionSeeder::class);
112:         $this->seed(RolePermissionSeeder::class);
113: 
114:         $superAdmin = Role::findByName(StaffRole::SuperAdmin->value, 'staff');
115:         $staffPermissionsCount = Permission::query()->where('guard_name', 'staff')->count();
116: 
117:         $this->assertSame($staffPermissionsCount, $superAdmin->permissions()->count());
118:         $this->assertGreaterThan(40, $staffPermissionsCount);
119:     }
120: 
121:     private function staffWithPermission(string $permissionName): User
122:     {
123:         $permission = Permission::findOrCreate($permissionName, 'staff');
124:         $staff = User::factory()->create();
125:         $staff->givePermissionTo($permission);
126: 
127:         return $staff;
128:     }
129: 
130:     /**
131:      * @param array<string, mixed> $payload
132:      */
133:     private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
134:     {
135:         Auth::forgetGuards();
136: 
137:         return $this->withToken($token)->json($method, $uri, $payload);
138:     }
139: }
```

## File: tests/TestCase.php
```php
 1: <?php
 2: 
 3: namespace Tests;
 4: 
 5: use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
 6: 
 7: abstract class TestCase extends BaseTestCase
 8: {
 9:     //
10: }
```

## File: phpunit.xml
```xml
 1: <?xml version="1.0" encoding="UTF-8"?>
 2: <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
 3:          xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
 4:          bootstrap="vendor/autoload.php"
 5:          colors="true"
 6: >
 7:     <testsuites>
 8:         <testsuite name="Unit">
 9:             <directory>tests/Unit</directory>
10:         </testsuite>
11:         <testsuite name="Feature">
12:             <directory>tests/Feature</directory>
13:         </testsuite>
14:     </testsuites>
15:     <source>
16:         <include>
17:             <directory>app</directory>
18:         </include>
19:     </source>
20:     <php>
21:         <env name="APP_ENV" value="testing"/>
22:         <env name="APP_MAINTENANCE_DRIVER" value="file"/>
23:         <env name="BCRYPT_ROUNDS" value="4"/>
24:         <env name="BROADCAST_CONNECTION" value="null"/>
25:         <env name="CACHE_STORE" value="array"/>
26:         <env name="DB_CONNECTION" value="sqlite"/>
27:         <env name="DB_DATABASE" value=":memory:"/>
28:         <env name="DB_URL" value=""/>
29:         <env name="MAIL_MAILER" value="array"/>
30:         <env name="QUEUE_CONNECTION" value="sync"/>
31:         <env name="SESSION_DRIVER" value="array"/>
32:         <env name="PULSE_ENABLED" value="false"/>
33:         <env name="TELESCOPE_ENABLED" value="false"/>
34:         <env name="NIGHTWATCH_ENABLED" value="false"/>
35:     </php>
36: </phpunit>
```
