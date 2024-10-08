<?php
class User extends Controller
{
	private $driver_model;
	private $officer_model;
	private $otp_model;



	public function __construct()
	{
		$this->driver_model = $this->model("DriverModel");
		$this->officer_model = $this->model("OfficerModel");
		$this->otp_model = $this->model("OtpModel");
	}



	// generate a new otp and add it to the db
	private function generate_otp($mobile_number)
	{
		$code = rand(1000, 9999); // generate a new otp
		$otp_data = [
			"mobile_number" => $mobile_number,
			"code" => hash('sha256', (string)$code),
			"time_stamp" => time()
		];

		$this->otp_model->add_otp($otp_data); // add new record to db

		return $code;
	}



	// send the otp sms
	private function send_sms($code, $mobile_number)
	{
		$text = "Your+OTP+code+for+PARKnGO%3A+" . $code . ".+Please+use+this+code+to+complete+your+authentication.+Thank+you%21";
		$url = "https://www.textit.biz/sendmsg?id=94713072925&pw=" . TEXTIT_KEY . "&to=0" . $mobile_number . "&text=" . $text;
		file_get_contents($url);
	}



	// check otp is correct or not
	public function check_otp($code, $mobile_number)
	{
		// mobile number is always exist for this function call, thus data will be returned
		$otp_data = $this->otp_model->is_mobile_number_exist($mobile_number);
		if ($otp_data !== false && ($otp_data["code"] == hash('sha256', (string)$code))) {
			if (time() - $otp_data["time_stamp"] > 60) {
				return 2; // expired otp
			} else {
				return 1; // valid otp
			}
		} else {
			return 3; // invalid otp
		}
	}



	// send otp in login process
	public function send_otp_login($user_type, $mobile_number)
	{
		if ($user_type == "driver") // if user is a driver
		{
			if ($this->driver_model->is_mobile_number_exist($mobile_number)) // if exist
			{
				$this->send_otp($mobile_number);
			} else // mobile number is not exist 
			{
				$this->send_json_400("Mobile number is not a registered one");
			}
		} else // user is a officer
		{
			if ($this->officer_model->is_mobile_number_exist($mobile_number)) // if exist
			{
				$this->send_otp($mobile_number);
			} else // mobile number is not exist 
			{
				$this->send_json_400("Mobile number is not a registered one");
			}
		}
	}



	// send otp in register process
	public function send_otp_register($mobile_number)
	{
		if ($this->driver_model->is_mobile_number_exist($mobile_number)) // if exist
		{
			$this->send_json_400("Mobile number is already registered");
		} else // mobile number is not exist 
		{
			$this->send_otp($mobile_number);
		}
	}



	// for resend-otp button
	public function send_otp($mobile_number)
	{
		$otp_data = $this->otp_model->is_mobile_number_exist($mobile_number);

		if ($otp_data == false) // mobile number not exist
		{
			// generate and add the new otp to db
			$code = $this->generate_otp($mobile_number);

			// send the sms
			$this->send_sms($code, $mobile_number);

			$this->send_json_200("SUCCESS");
		} else // mobile number exist
		{
			$time_diff = time() - $otp_data["time_stamp"];
			if ($time_diff > 60) // has expired
			{
				// delete the exisiting one
				$this->otp_model->delete_otp($mobile_number);

				// generate and add the new otp to db
				$code = $this->generate_otp($mobile_number);

				// send the sms
				$this->send_sms($code, $mobile_number);

				$this->send_json_200("SUCCESS");
			} else // has not expired
			{
				$this->send_json_400("Wait " . (60 - $time_diff) . " seconds");
			}
		}
	}



	// register
	public function register($code)
	{
		$driver_data = [
			"first_name" => trim($_POST["first_name"]),
			"last_name" => trim($_POST["last_name"]),
			"mobile_number" => trim($_POST["mobile_number"])
		];

		// check_otp() return
		// 1 => valid
		// 2 => expired otp
		// 3 => invalid otp

		switch ($this->check_otp($code, $driver_data["mobile_number"])) {
			case 1:
				$this->driver_model->add_driver($driver_data);
				$this->otp_model->delete_otp($driver_data["mobile_number"]);
				$this->send_response_with_token("driver", $driver_data["mobile_number"], "Registration"); // send the response with tokens
				break;
			case 2:
				$this->send_json_400("OTP has been expired");
				break;
			case 3:
				$this->send_json_400("Invalid OTP");
				break;
		}
	}



	// login
	public function login($user_type, $mobile_number, $code)
	{
		// check_otp() return
		// 1 => valid otp
		// 2 => expired otp
		// 3 => invalid otp

		switch ($this->check_otp($code, $mobile_number)) {
			case 1:
				$this->otp_model->delete_otp($mobile_number);
				$this->send_response_with_token($user_type, $mobile_number, "Login"); // send the response with tokens
				break;
			case 2:
				$this->send_json_400("OTP has been expired");
				break;
			case 3:
				$this->send_json_400("Invalid OTP");
				break;
		}
	}



	// Send Json response for a succefull login or a registration  
	private function send_response_with_token($user_type, $mobile_number, $op)
	{
		$token_data = null;
		$user_data = null;

		if ($user_type == "driver") // user is a driver
		{
			// fetch driver data
			$driver_data = $this->driver_model->get_driver($mobile_number);


			$token_data = [
				"user_id" => $driver_data["_id"],
				"user_type" => "driver",
				"time_stamp" => time()
			];

			$user_data = [
				"first_name" => $driver_data["first_name"],
				"last_name" => $driver_data["last_name"],
				"mobile_number" => $driver_data["mobile_number"]
			];
		} else // user is a officer
		{
			// fetch parking officer data
			$officer_data = $this->officer_model->get_officer($mobile_number);


			$token_data = [
				"user_id" => $officer_data["_id"],
				"user_type" => "officer",
				"time_stamp" => time()
			];

			$user_data = [
				"officer_id" => $officer_data["officer_id"],
				"nic" => $officer_data["nic"],
				"first_name" => $officer_data["first_name"],
				"last_name" => $officer_data["last_name"],
				"mobile_number" => $officer_data["mobile_number"],
				"parking_name" => $officer_data["parking_name"],
				"parking_id" => $this->encrypt_id($officer_data["parking_id"]),
				"company_name" => $officer_data["company_name"],
				"company_phone_number" => $officer_data["company_phone_number"]
			];
		}


		// generate a token
		$token = $this->encode_token($token_data);

		$response = ["response" => "$op Successfull", "user_data" => $user_data, "token" => $token];
		$response = json_encode($response);
		header('Content-Type: application/json');
		echo $response;
	}



	// validate the token when start of the app => decide user is already logged in or not
	public function validate_token()
	{
		if (isset($_SERVER['HTTP_TOKEN'])) {
			$token = $_SERVER['HTTP_TOKEN'];

			$token_data = $this->decode_token($token);

			if (!isset($token_data["user_type"]) || !isset($token_data["user_id"]) || !isset($token_data["time_stamp"])) // token is invalid 
			{
				$this->send_json_400("Invalid Token");
			} else // token has correct keys
			{
				if ($token_data["user_type"] == "driver" and $this->driver_model->is_driver_id_exist($token_data["user_id"])) // token valid => user id valid
				{
					$this->token_life_time_handler($token_data);
				} elseif ($token_data["user_type"] == "officer" and $this->officer_model->is_officer_id_exist($token_data["user_id"])) // token valid => user id valid
				{
					$this->token_life_time_handler($token_data);
				} else // token invalid
				{
					$this->send_json_400("Invalid Token");
				}
			}
		} else // token not found in the request
		{
			$this->send_json_404("token not found");
		}
	}



	// handle token's life cycle. 
	// Invalid token if token older than 2 month (5184000)
	// token will be refreshed with new timestamp if it older than 3weeks (1814400)
	// otherwise token will be consider as a new one.
	private function token_life_time_handler($token_data)
	{
		if (time() - $token_data["time_stamp"] > 5184000) // token has been expired(too old to refresh)
		{
			$this->send_json_400("Invalid Token");
		} elseif (time() - $token_data["time_stamp"] > 1814400) // token need to be refreshed
		{
			$new_token_data = [
				"user_id" => $token_data["user_id"],
				"user_type" => $token_data["user_type"],
				"time_stamp" => time()
			];

			// generate a token string
			$token = $this->encode_token($new_token_data);

			$this->send_json_200_with_token("Token Refreshed", $token);
		} else // token is valid and a new one
		{
			$this->send_json_200("Valid Token");
		}
	}
}
