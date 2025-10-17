<?php

class ErrorHandler {
    private static $logFile = null;
    
    public static function init() {
        self::$logFile = __DIR__ . '/../logs/error.log';
        
        // Ensure logs directory exists
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        // Set error and exception handlers
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    public static function handleError($severity, $message, $filename, $lineno) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $errorTypes = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING', 
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED'
        ];
        
        $type = $errorTypes[$severity] ?? 'UNKNOWN';
        self::log("PHP $type: $message in $filename on line $lineno");
        
        // Don't execute PHP's internal error handler
        return true;
    }
    
    public static function handleException($exception) {
        self::log("EXCEPTION: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
        self::log("Stack trace: " . $exception->getTraceAsString());
        
        // Display user-friendly error page
        if (!headers_sent()) {
            http_response_code(500);
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Internal server error']);
            } else {
                self::displayErrorPage('Application Error', 'An error occurred while processing your request.');
            }
        }
    }
    
    public static function handleShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::log("FATAL ERROR: {$error['message']} in {$error['file']} on line {$error['line']}");
            
            if (!headers_sent()) {
                self::displayErrorPage('Fatal Error', 'A fatal error occurred.');
            }
        }
    }
    
    public static function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        
        if (self::$logFile) {
            file_put_contents(self::$logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }
        
        // Also log to system log in production
        if (!Config::isDebug()) {
            error_log($message);
        }
    }
    
    private static function displayErrorPage($title, $message) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo htmlspecialchars($title); ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container mt-5">
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                                <h3><?php echo htmlspecialchars($title); ?></h3>
                                <p class="text-muted"><?php echo htmlspecialchars($message); ?></p>
                                <a href="javascript:history.back()" class="btn btn-primary">Go Back</a>
                                <a href="/ari-dialer/" class="btn btn-outline-secondary">Home</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}