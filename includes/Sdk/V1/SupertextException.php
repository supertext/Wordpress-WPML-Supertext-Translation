<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\V1;

/**
 * Any failure talking to Supertext.
 *
 * `$errorCode` is one of the constants below (fixed strings, the same in every Supertext SDK), so an
 * integration can show its own, translated message for it. The English `getMessage()` is safe to show
 * or log: it never contains the API key.
 */
final class SupertextException extends \RuntimeException
{
    public const MISSING_API_KEY = 'missing_api_key';
    public const AUTHENTICATION_FAILURE = 'authentication_failure';
    public const INVALID_LANGUAGE_PAIR = 'invalid_language_pair';
    public const UNSUPPORTED_LANGUAGE = 'unsupported_language';
    public const UNSUPPORTED_FEATURE = 'unsupported_feature';
    public const NOT_FOUND = 'not_found';
    public const PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const TOO_MANY_REQUESTS = 'too_many_requests';
    public const SERVICE_UNAVAILABLE = 'service_unavailable';
    public const UNEXPECTED_STATUS = 'unexpected_status';
    public const TRANSPORT_ERROR = 'transport_error';
    public const NO_FILE_ID = 'no_file_id';
    public const TRANSLATION_ERROR = 'translation_error';
    public const QUOTA_EXCEEDED = 'quota_exceeded';
    public const FILE_DELETED = 'file_deleted';
    public const TIMEOUT = 'timeout';
    public const INCOMPLETE_RESPONSE = 'incomplete_response';

    private const KEY_LINKS = ' No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin.'
        . ' Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).';

    private const MESSAGES = [
        self::MISSING_API_KEY => 'No Supertext API key is configured.' . self::KEY_LINKS,
        self::AUTHENTICATION_FAILURE => 'Supertext rejected the API key. Please check it.' . self::KEY_LINKS,
        self::INVALID_LANGUAGE_PAIR => 'Supertext does not support this language pair.',
        self::UNSUPPORTED_LANGUAGE => 'This language has no Supertext language code. Map it to a Supertext language.',
        self::UNSUPPORTED_FEATURE => 'Supertext does not support this option for the language pair.',
        self::NOT_FOUND => 'The file was not found at Supertext (it may have expired or been deleted).',
        self::PAYLOAD_TOO_LARGE => 'The document is too large for Supertext.',
        self::TOO_MANY_REQUESTS => 'Too many requests to Supertext. Please try again shortly.',
        self::SERVICE_UNAVAILABLE => 'The Supertext service is currently unavailable.',
        self::UNEXPECTED_STATUS => 'Supertext answered with an unexpected HTTP status.',
        self::TRANSPORT_ERROR => 'Could not reach Supertext.',
        self::NO_FILE_ID => 'Supertext did not return a file id.',
        self::TRANSLATION_ERROR => 'Supertext could not translate the document.',
        self::QUOTA_EXCEEDED => 'The Supertext translation limit of this account is used up.',
        self::FILE_DELETED => 'The document was deleted at Supertext before it could be downloaded.',
        self::TIMEOUT => 'Timed out waiting for the Supertext translation.',
        self::INCOMPLETE_RESPONSE => 'The translation from Supertext was empty or incomplete.',
    ];

    /**
     * @param string      $errorCode    one of the constants of this class
     * @param int|null    $status       HTTP status, if the error came from an HTTP answer
     * @param string|null $detail       short text from the API's answer (tags removed, max 200 characters)
     * @param string|null $apiErrorCode the answer's JSON `error_code`, e.g. "INVALID_LANGUAGE_PAIR"
     * @param string|null $sourceLang   invalid_language_pair, unsupported_feature: the API's source_lang;
     *                                  unsupported_language: the source code that has no Supertext code
     * @param string|null $targetLang   invalid_language_pair, unsupported_feature: the API's target_lang;
     *                                  unsupported_language: the target code that has no Supertext code
     * @param string|null $feature      unsupported_feature: the option the API names, e.g. "politeness"
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $status = null,
        public readonly ?string $detail = null,
        public readonly ?string $apiErrorCode = null,
        public readonly ?string $sourceLang = null,
        public readonly ?string $targetLang = null,
        ?\Throwable $previous = null,
        public readonly ?string $feature = null,
    ) {
        $message = self::MESSAGES[$errorCode] ?? 'Supertext request failed.';
        if ($status !== null) {
            $message .= ' (HTTP ' . $status . ')';
        }
        if ($detail !== null && $detail !== '') {
            $message .= ' ' . $detail;
        }
        parent::__construct($message, $status ?? 0, $previous);
    }
}
