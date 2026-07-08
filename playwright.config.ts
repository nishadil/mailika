import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  use: {
    baseURL: 'http://127.0.0.1:8090'
  },
  webServer: {
    command: [
      'APP_ENV=testing',
      'APP_DEBUG=false',
      'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      'SESSION_SECURE_COOKIE=false',
      'MAILIKA_DATA_STORE=session',
      'MAILIKA_IMAP_ADAPTER=fixture',
      'MAILIKA_IMAP_VALIDATE_LOGIN=true',
      'MAILIKA_RATE_LIMIT_LOGIN_ATTEMPTS=100',
      'php -S 127.0.0.1:8090 -t public public/index.php'
    ].join(' '),
    url: 'http://127.0.0.1:8090/healthz',
    reuseExistingServer: true,
    timeout: 15_000
  }
});
