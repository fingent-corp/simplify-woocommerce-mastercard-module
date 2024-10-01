<?php
/**
 * Copyright (c) 2023-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main class of the Mastercard Simplify Api Logger
 * 
 * This class handles logging for the Mastercard Simplify API. It is responsible for recording API request and response data, 
 * as well as any errors or relevant information that may be useful for debugging or monitoring API interactions.
 */
class Mastercard_Simplify_Api_Logger {
	/**
     * Cipher method used for encryption/decryption.
     * 
     * @var string
     */
    protected $cipher;

    /**
     * The algorithm used for encryption and decryption operations.
     * 
     * @var string
     */
    protected $cipher_algo;

    /**
     * This property holds the name of the file to be used or processed.
     * 
     * @var string
     */
    protected $filename;

	/**
     * Constructor function that initializes the object with a given hash value.
     */
    public function __construct( $hash ) {
        $this->cipher_algo = "AES-256-CBC";
    	$this->cipher      = $hash;
        $this->filename    = WP_CONTENT_DIR . '/mastercard_simplify.log';
    }

    /**
     * Encrypts a given log entry.
     *
     * This function takes a plain text log entry as input and returns the encrypted version of the log.
     * The encryption method and key should be securely managed to ensure the confidentiality of the log data.
     *
     * @param string $plain_text The log entry in plain text format.
     * @return string The encrypted log entry.
     */
    public function encrypt_log( $plain_text ) {
    	$iv_len         = openssl_cipher_iv_length( $this->cipher_algo );
	    $iv             = openssl_random_pseudo_bytes( $iv_len );
	    $cipher_text    = openssl_encrypt( $plain_text, $this->cipher_algo, $this->cipher, $options = 0, $iv );
	    $cipher_text_iv = base64_encode( $iv . $cipher_text );

	    return $cipher_text_iv;
    }

    /**
     * Encrypts and writes a log message to a secure log file.
     *
     * @param string $message The log message to be encrypted and stored.
     */
    public function write_encrypted_log( $message ) {
	    $encrypted_message = $this->encrypt_log( $message, $this->cipher );
	    file_put_contents( $this->filename, rtrim( $encrypted_message ) . PHP_EOL, FILE_APPEND );
	}

    /**
     * Decrypts a log entry using the provided cipher text and initialization vector (IV).
     *
     * @param string $cipher_text_iv The encrypted log entry, including the initialization vector.
     * @return string The decrypted log entry.
     */
    public function decrypt_log( $cipher_text_iv ) {
        $iv_len             = openssl_cipher_iv_length( $this->cipher_algo ); 
        $cipher_text_iv     = base64_decode( $cipher_text_iv );
        $iv                 = substr( $cipher_text_iv, 0, $iv_len );
        $cipher_text        = substr( $cipher_text_iv, $iv_len );
        $original_plaintext = openssl_decrypt( $cipher_text, $this->cipher_algo, $this->cipher, $options = 0, $iv );

        return $original_plaintext;
    }

    /**
     * Reads and processes the decrypted log file.
     * 
     * This function handles the reading of the log file after it has been decrypted. 
     * It assumes the file is in a readable format and will process its contents accordingly.
     * Make sure the file has been decrypted before calling this function.
     * 
     * @return void
     */
    public function read_decrypted_log() {
        $decrypted_log_data = [];
        $log_entries = file( $this->filename, FILE_IGNORE_NEW_LINES );

        if( $log_entries ) {
            foreach ( $log_entries as $cipher_text ) {
                if ( ! empty( $cipher_text ) )  {
                    $decrypted_message = $this->decrypt_log( $cipher_text );
                    $decrypted_log_data[] = rtrim( $decrypted_message ) . PHP_EOL;
                }
            }

            if( $decrypted_log_data ) {
                $decrypted_log_data = implode( '', $decrypted_log_data );
            }
        }

        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="mastercard_simplify.log"' );
        header( 'Content-Length: ' . strlen( $decrypted_log_data ) );
        echo $decrypted_log_data;
    }
}
