<?php

    namespace App;

    use Illuminate\Notifications\Notifiable;
    use Illuminate\Contracts\Auth\MustVerifyEmail;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Config;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\URL;
    use Modules\Agencies\Models\Agencies;
    use Modules\Agencies\Models\AgenciesAgent;
    use Modules\Booking\Models\Booking;
    use Modules\Review\Models\Review;
    use Modules\User\Emails\EmailUserVerifyRegister;
    use Modules\User\Emails\ResetPasswordToken;
//    use Modules\Vendor\Models\VendorPlan;
    use Modules\Vendor\Models\VendorPayout;
    use Modules\Vendor\Models\VendorRequest;
    use Spatie\Permission\Traits\HasRoles;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Database\Eloquent\SoftDeletes;
    use Illuminate\Support\Facades\Auth;
    use Tymon\JWTAuth\Contracts\JWTSubject;

    class User extends Authenticatable implements MustVerifyEmail,JWTSubject
    {
        use SoftDeletes;
        use Notifiable;
        use HasRoles;
//        protected $appends = ['vendorPlanData'];

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         */
        protected $fillable = [
            'name',
            'first_name',
            'last_name',
            'email',
            'email_verified_at',
            'password',
            'address',
            'address2',
            'phone',
            'birthday',
            'city',
            'state',
            'country',
            'zip_code',
            'last_login_at',
            'avatar_id',
            'bio',
            'business_name',
            'review_score',
            'user_social'
//            'vendor_plan_id',
//            'vendor_plan_enable',
//            'vendor_plan_start_date',
//            'vendor_plan_end_date',
        ];

        protected $type = 'agent';

        /**
         * The attributes that should be hidden for arrays.
         *
         * @var array
         */
        protected $hidden = [
            'password', 'remember_token',
        ];

        /**
         * The attributes that should be cast to native types.
         *
         * @var array
         */
        protected $casts = [
            'email_verified_at' => 'datetime',
        ];

        protected $reviewClass;


        public function __construct(array $attributes = [])
        {
            parent::__construct($attributes);
            $this->reviewClass = Review::class;
        }

        public function getReviewEnable()
        {
            return setting_item("agent_enable_review", 0);
        }

        public function check_enable_review_after_booking()
        {
            $option = setting_item("agent_enable_review_after_booking", 0);
            if ($option) {
                $number_review = $this->reviewClass::countReviewByServiceID($this->id, Auth::id()) ?? 0;
                $number_booking = $this->bookingClass::countBookingByServiceID($this->id, Auth::id()) ?? 0;
                if ($number_review >= $number_booking) {
                    return false;
                }
            }
            return true;
        }

        public function check_allow_review_after_making_completed_booking()
        {
            $options = setting_item("agent_allow_review_after_making_completed_booking", false);
            if (!empty($options)) {
                $status = json_decode($options);
                $booking = $this->bookingClass::select("status")
                    ->where("object_id", $this->id)
                    ->where("object_model", $this->type)
                    ->where("customer_id", Auth::id())
                    ->orderBy("id","desc")
                    ->first();
                $booking_status = $booking->status ?? false;
                if(!in_array($booking_status , $status)){
                    return false;
                }
            }
            return true;
        }

        public function getReviewApproved()
        {
            return setting_item("agent_review_approved", 0);
        }

        public function update_service_rate(){
            $rateData = Review::selectRaw("AVG(rate_number) as rate_total")->where('object_id', $this->id)->where('object_model',$this->type)->where("status", "approved")->first();
            $rate_number = number_format( $rateData->rate_total ?? 0 , 1);
            $this->review_score = $rate_number;
            $this->save();
        }

        public function getReviewDataAttribute()
        {
            $list_score = [
                'score_total'  => 0,
                'score_text'   => __("Not Rated"),
                'total_review' => 0,
                'rate_score'   => [],
            ];
            $dataTotalReview = $this->reviewClass::selectRaw(" AVG(rate_number) as score_total , COUNT(id) as total_review ")->where('object_id', $this->id)->where('object_model', "agent")->where("status", "approved")->first();
            if (!empty($dataTotalReview->score_total)) {
                $list_score['score_total'] = number_format($dataTotalReview->score_total, 1);
                $list_score['score_text'] = $this->reviewClass::getDisplayTextScoreByLever(round($list_score['score_total']));
            }
            if (!empty($dataTotalReview->total_review)) {
                $list_score['total_review'] = $dataTotalReview->total_review;
            }
            $list_data_rate = $this->reviewClass::selectRaw('COUNT( CASE WHEN rate_number = 5 THEN rate_number ELSE NULL END ) AS rate_5,
                                                            COUNT( CASE WHEN rate_number = 4 THEN rate_number ELSE NULL END ) AS rate_4,
                                                            COUNT( CASE WHEN rate_number = 3 THEN rate_number ELSE NULL END ) AS rate_3,
                                                            COUNT( CASE WHEN rate_number = 2 THEN rate_number ELSE NULL END ) AS rate_2,
                                                            COUNT( CASE WHEN rate_number = 1 THEN rate_number ELSE NULL END ) AS rate_1 ')->where('object_id', $this->id)->where('object_model', $this->type)->where("status", "approved")->first()->toArray();
            for ($rate = 5; $rate >= 1; $rate--) {
                if (!empty($number = $list_data_rate['rate_' . $rate])) {
                    $percent = ($number / $list_score['total_review']) * 100;
                } else {
                    $percent = 0;
                }
                $list_score['rate_score'][$rate] = [
                    'title'   => $this->reviewClass::getDisplayTextScoreByLever($rate),
                    'total'   => $number,
                    'percent' => round($percent),
                ];
            }
            return $list_score;
        }

        public function getMeta($key, $default = '')
        {
            //if(isset($this->cachedMeta[$key])) return $this->cachedMeta[$key];

            $val = DB::table('user_meta')->where([
                'user_id' => $this->id,
                'name'    => $key
            ])->first();

            if (!empty($val)) {
                //$this->cachedMeta[$key]  = $val->val;
                return $val->val;
            }

            return $default;
        }

        public function addMeta($key, $val, $multiple = false)
        {
            if(is_array($val) or is_object($val)) $val = json_encode($val);
            if ($multiple) {
                return DB::table('user_meta')->insert([
                    'name'    => $key,
                    'val'     => $val,
                    'user_id' => $this->id,
                    'create_user'=>Auth::id(),
                    'created_at'=>date('Y-m-d H:i:s')
                ]);
            } else {
                $old = DB::table('user_meta')->where([
                    'user_id' => $this->id,
                    'name'    => $key
                ])->first();

                if ($old) {
                    return DB::table('user_meta')->where('id', $old->id)->update([
                        'val' => $val,
                        'update_user'=>Auth::id(),
                        'updated_at'=>date('Y-m-d H:i:s')
                    ]);
                } else {
                    return DB::table('user_meta')->insert([
                        'name'    => $key,
                        'val'     => $val,
                        'user_id' => $this->id,
                        'create_user'=>Auth::id(),
                        'created_at'=>date('Y-m-d H:i:s')
                    ]);
                }
            }

        }

        public function updateMeta($key,$val){

            return DB::table('user_meta')->where('user_id', $this->id)
                ->where('key',$key)
                ->update([
                'val' => $val,
                'update_user'=>Auth::id(),
                'updated_at'=>date('Y-m-d H:i:s')
            ]);
        }

        public function batchInsertMeta($metaArrs = [])
        {
            if (!empty($metaArrs)) {
                foreach ($metaArrs as $key => $val) {
                    $this->addMeta($key, $val, true);
                }
            }
        }

        public function getNameOrEmailAttribute()
        {
            if ($this->first_name) return $this->first_name;

            return $this->email;
        }


        public function getStatusTextAttribute()
        {
            switch ($this->status) {
                case "publish":
                    return __("Publish");
                    break;
                case "blocked":
                    return __("Blocked");
                    break;
            }
        }

        public static function getUserBySocialId($provider, $socialId)
        {
            return parent::query()->select('users.*')->join('user_meta as m', 'm.user_id', 'users.id')
                ->where('m.name', 'social_' . $provider . '_id')
                ->where('m.val', $socialId)->first();
        }

        public function getAvatarUrl()
        {
            if (!empty($this->avatar_id)) {
                return get_file_url($this->avatar_id, 'thumb');
            }
            if(!empty($meta_avatar = $this->getMeta("social_meta_avatar",false))) {
                return $meta_avatar;
            }
            return false;
        }

        public function getDisplayName($email = false)
        {
            $name = $this->name;
            if (!empty($this->first_name) or !empty($this->last_name)) {
                $name = implode(' ', [$this->first_name, $this->last_name]);
            }
            if( !empty($this->business_name) ){
                $name  = $this->business_name;
            }
            if(!trim($name) and $email) $name = $this->email;
            return $name;
        }

        public function sendPasswordResetNotification($token)
        {
            Mail::to($this->email)->send(new ResetPasswordToken($token));
        }

        public static function boot()
        {
            parent::boot();
            static::saving(function ($table) {
                $table->name = implode(' ', [$table->first_name, $table->last_name]);
            });
        }

//        public function vendorPlan()
//        {
//            return $this->belongsTo(VendorPlan::class, 'vendor_plan_id');
//        }
//
//        public function getVendorPlanDataAttribute()
//        {
//            $data = [];
//            if ($this->hasRole('agent')) {
//                $plan = $this->vendorPlan()->first();
//                if (!empty($plan)) {
//                    $enable = !empty($this->vendor_plan_enable) ? true : false;
//                    if ($plan->status == 'publish') {
//                        $enable = true;
//                    } else {
//                        $enable = false;
//                    }
//                    $data['vendor_plan_enable'] = $enable;
//                    $data['base_commission'] = $plan->base_commission;
//                    $planMeta = $plan->meta;
//                    foreach ($planMeta as $meta){
//                        $data[$meta->post_type] = $meta->toArray();
//                    }
//                }
//            }
//            return $data;
//        }

        public function getVendorServicesQuery($moduleClass,$limit = 10){
            return $moduleClass::getVendorServicesQuery()->take($limit);
        }

        public function getReviewCountAttribute(){
            return Review::query()->where('vendor_id',$this->id)->where('status','approved')->count('id');
        }
        public function vendorRequest(){
            return $this->hasOne(VendorRequest::class);
        }

        public function property()
        {
            return $this->hasMany('Modules\Property\Models\Property', 'create_user', 'id')->where('bravo_properties.status', '=', 'publish');
        }

        public function getPayoutAccountsAttribute(){
            return json_decode($this->getMeta('vendor_payout_accounts'));
        }

        /**
         * Get total available amount for payout at current time
         */
        public function getAvailablePayoutAmountAttribute(){
            $status = setting_item_array('vendor_payout_booking_status');
            if(empty($status)) return 0;

            $query = Booking::query();

            $total =  $query
                ->whereIn('status',$status)
                ->where('vendor_id',$this->id)
                ->sum(DB::raw('total_before_fees - commission')) - $this->total_paid;
            return max(0,$total);
        }

        public function getTotalPaidAttribute(){
            return VendorPayout::query()->where('status','!=','rejected')->where([
                'vendor_id'=>$this->id
            ])->sum('amount');
        }

        public function getAvailablePayoutMethodsAttribute()
        {
            $vendor_payout_methods = json_decode(setting_item('vendor_payout_methods'));
            if(!is_array($vendor_payout_methods)) $vendor_payout_methods = [];

            $vendor_payout_methods = array_values(\Illuminate\Support\Arr::sort($vendor_payout_methods, function ($value) {
                return $value->order ?? 0;
            }));

            $res = [];

            $accounts = $this->payout_accounts;

            if(!empty($vendor_payout_methods) and !empty($accounts))
            {
                foreach ($vendor_payout_methods as $vendor_payout_method) {
                    $id = $vendor_payout_method->id;

                    if(!empty($accounts->$id))
                    {
                        $vendor_payout_method->user = $accounts->$id;
                        $res[$id] = $vendor_payout_method;
                    }
                }
            }

            return $res;
        }

        public function getRoleNameAttribute(){
            $all = $this->getRoleNames();

            if(count($all)){
                return ucfirst($all[0]);
            }
            return '';
        }

        public function getRoleIdAttribute(){
            return $this->roles[0]->id ?? '';
        }

        /**
         * @todo get All Fields That you need to verification
         * @return array
         */
        public function getVerificationFieldsAttribute(){

            $all = get_all_verify_fields();
            $role_id = $this->role_id;
            $res = [];
            foreach ($all as $id=>$field)
            {
                if(!empty($field['roles']) and is_array($field['roles']) and in_array($role_id,$field['roles']))
                {
                    $field['id'] = $id;
                    $field['field_id'] = 'verify_data_'.$id;
                    $field['is_verified'] = $this->isVerifiedField($id);
                    $field['data'] = old('verify_data_'.$id,$this->getVerifyData($id));

                    switch ($field['type'])
                    {
                        case "multi_files":
                            $field['data'] = json_decode($field['data'],true);
                            if(!empty($field['data']))
                            {
                                foreach ($field['data'] as $k=>$v){
                                    if(!is_array($v)){
                                        $field['data'][$k] = json_decode($v,true);
                                    }
                                }
                            }
                            break;
                    }
                    $res[$id] = $field;
                }
            }

            return \Illuminate\Support\Arr::sort($res, function ($value) {
                return $value['order'] ?? 0;
            });

        }

        public function isVerifiedField($field_id){
            return (bool) $this->getMeta('is_verified_'.$field_id);
        }
        public function getVerifyData($field_id){
            return $this->getMeta('verify_data_'.$field_id);
        }

        public static function countVerifyRequest(){
            return parent::query()->whereIn('verify_submit_status',['new','partial'])->count(['id']);
        }

        public function sendEmailVerificationNotification(){
            $this->notify(new \Modules\User\Emails\VerifyEmailNotification());
        }


        public function getJWTIdentifier()
        {
            return $this->getKey();
        }
        /**
         * Return a key value array, containing any custom claims to be added to the JWT.
         *
         * @return array
         */
        public function getJWTCustomClaims()
        {
            return [];
        }

        public static function getListUser($agenciesId = null)
        {
            $listAgentHasAgencies = AgenciesAgent::query();
            if (!empty($agenciesId)) {
                $listAgentHasAgencies->where('agencies_id', '<>', $agenciesId);
            }
            $listAgentHasAgencies = $listAgentHasAgencies->pluck('agent_id')->toArray();
            $users = User::select('*')
                ->whereNotIn('id', $listAgentHasAgencies)
                ->orderBy('id', 'desc')->orderBy('first_name', 'asc')->limit(20)->get();
            $data = [];
            foreach ($users as $item) {
                $data[] = [
                    'id'   => $item->id,
                    'name' => $item->email,
                ];
            }
            return $data;
        }

        public function verificationUrl(){
            return URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
                ['id' => $this->getKey(),
                 'hash' => sha1($this->getEmailForVerification()),
                ]
            );
        }

        public function getDisplayNameAttribute()
        {
            $name = $this->name;
            if (!empty($this->first_name) or !empty($this->last_name)) {
                $name = implode(' ', [$this->first_name, $this->last_name]);
            }
            if( !empty($this->business_name) ){
                $name  = $this->business_name;
            }
            return $name;
        }


        // public function propertyCategory() {
        //     return $this->hasManyThrough('Modules\Property\Models\PropertyCategory', 'Modules\Property\Models\Property', 'category_id', 'id', 'id', 'create_user');
        // }
        public function agency()
        {
            return $this->belongsTo(Agencies::class,'id','create_user');
        }
    }

