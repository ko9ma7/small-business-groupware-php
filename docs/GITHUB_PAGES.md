# GitHub Pages와 PHP 운영판 선택

## 결론

두 저장소의 역할을 분리합니다.

| 구분 | PHP·MySQL 운영판 | GitHub Pages 공개 데모 |
|---|---|---|
| 목적 | 실제 사내 운영 | UI와 사용 흐름 공개 |
| 가입·로그인 | 실제 세션 인증 | `test / 1111` 화면 체험 |
| 데이터 저장 | MySQL | 저장하지 않음 |
| 전자결재·권한 | 실제 동작 | 가상 예시 |
| 설치 위치 | 닷홈 등 PHP 호스팅 | GitHub Pages |
| 저장소 | `small-business-groupware-php` | `small-business-groupware-pages-demo` |

## GitHub Pages만으로 실제 운영할 수 없는 이유

GitHub Pages는 HTML·CSS·JavaScript를 제공하는 정적 호스팅이며 PHP 같은 서버 측 언어를 실행하지 않습니다. GitHub Actions는 빌드와 배포를 위한 일시적인 작업 실행 환경으로, 사용자의 로그인 요청을 계속 받거나 영구 DB 역할을 하지 않습니다.

가입, 로그인, DB 저장까지 서버 설치 없이 구성하려면 Supabase, Firebase, Cloudflare Workers/D1 같은 별도 백엔드 서비스가 필요합니다. 이 경우에도 서비스 계정, 인증 정책, 데이터 권한, 백업과 장애 대응을 설정해야 하므로 GitHub만으로 동작하는 것은 아닙니다.

## 개인정보 보호

공개 Pages 데모에는 실제 회사명, 직원명, 연락처, 보고 내용과 첨부파일을 넣지 않습니다. 공개 데모의 `test / 1111`은 보안 인증이 아니라 화면 진입을 위한 고정 예시이며, 실제 업무용 비밀번호로 사용하면 안 됩니다.
