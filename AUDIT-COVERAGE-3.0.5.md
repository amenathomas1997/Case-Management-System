# Audit coverage ledger — 3.0.5

Baseline: `1aa2bf0`. Hashes identify the final source inspected or checked in this pass. “Targeted source review” does not mean every line was independently verified. Other files are inventoried so remaining coverage is explicit. Earlier audit reports document previous passes.

| File | SHA-256 (first 12) | Coverage this pass |
|---|---|---|
| cron/at_reminders.php | c06302a07980 | PHP syntax; broader runtime coverage varies by route |
| cron/backup.sh | 29a12b861fe4 | Inventoried; no claim of individual source review |
| cron/daily_alerts.php | 42e68a08271b | PHP syntax; broader runtime coverage varies by route |
| cron/ingest_email.php | c4a4ebb0f0cb | PHP syntax; broader runtime coverage varies by route |
| cron/monthly_reports.php | 9cb8d832f6ee | PHP syntax; broader runtime coverage varies by route |
| cron/session_reminders.php | 0f2b7261332a | PHP syntax; broader runtime coverage varies by route |
| cron/watchdog.php | d524e0dad802 | PHP syntax; broader runtime coverage varies by route |
| cron/weekly_offsite.php | 84a9a459a9b3 | PHP syntax; broader runtime coverage varies by route |
| dist/install.php | cf4a0c5123a7 | Targeted source review and/or changed behavior verification |
| dist/make_dist.sh | a565ae10c167 | Targeted source review and/or changed behavior verification |
| dist/migrations/mig_2.45.0.sql | b3993c7335fc | Inventoried; no claim of individual source review |
| dist/migrations/mig_2.46.0.sql | f1fa6b7dbc2c | Inventoried; no claim of individual source review |
| dist/migrations/mig_2.48.0.sql | ee1a6265761a | Inventoried; no claim of individual source review |
| dist/migrations/mig_2.53.0.sql | 268e3900839b | Inventoried; no claim of individual source review |
| dist/migrations/mig_2.54.0.sql | 8929f9834ae4 | Inventoried; no claim of individual source review |
| dist/migrations/mig_2.55.0.sql | 2066320a2c7d | Inventoried; no claim of individual source review |
| dist/migrations/mig_2.62.0.sql | fd42f53ad024 | Inventoried; no claim of individual source review |
| dist/migrations/mig_2.73.0.sql | 57ed956fea06 | Inventoried; no claim of individual source review |
| dist/migrations/mig_3.0.1.sql | acc220423354 | Inventoried; no claim of individual source review |
| ios/Sources/AIToolsView.swift | a267ed02a7d4 | iOS build; individual behavior not independently verified |
| ios/Sources/API.swift | 6ada90291403 | Targeted source review and/or changed behavior verification |
| ios/Sources/ATServicesView.swift | e1929b864aec | iOS build; individual behavior not independently verified |
| ios/Sources/AccommodationsView.swift | c3f5a10a03dc | iOS build; individual behavior not independently verified |
| ios/Sources/BookingsView.swift | c105b2e9b1d5 | iOS build; individual behavior not independently verified |
| ios/Sources/ClientEditSheet.swift | b5c58d157a8e | iOS build; individual behavior not independently verified |
| ios/Sources/ClientsView.swift | e73b22405ea4 | Targeted source review and/or changed behavior verification |
| ios/Sources/CompetencyView.swift | c6175c2bcee7 | iOS build; individual behavior not independently verified |
| ios/Sources/ComplianceView.swift | 8ca4eefc0386 | iOS build; individual behavior not independently verified |
| ios/Sources/ContractsView.swift | 96c5164251b9 | iOS build; individual behavior not independently verified |
| ios/Sources/CounselorsView.swift | 2a6ef41ced9c | iOS build; individual behavior not independently verified |
| ios/Sources/DashboardView.swift | 9bc5788462fd | iOS build; individual behavior not independently verified |
| ios/Sources/DocumentsAndHomework.swift | 1a737393cbe2 | iOS build; individual behavior not independently verified |
| ios/Sources/EquipmentView.swift | ab7a24a4591c | iOS build; individual behavior not independently verified |
| ios/Sources/CaseManagementApp.swift | 8194756b4ada | iOS build; individual behavior not independently verified |
| ios/Sources/FollowupsView.swift | bc855abf833d | iOS build; individual behavior not independently verified |
| ios/Sources/IncidentsView.swift | 888961590f57 | iOS build; individual behavior not independently verified |
| ios/Sources/Keychain.swift | d6dc73d606c1 | iOS build; individual behavior not independently verified |
| ios/Sources/KnowledgeView.swift | 3da0d031a3d6 | iOS build; individual behavior not independently verified |
| ios/Sources/LogHoursView.swift | 47d9d2d595b1 | iOS build; individual behavior not independently verified |
| ios/Sources/LoginView.swift | ebcd670b6170 | iOS build; individual behavior not independently verified |
| ios/Sources/MessagesView.swift | 03c8eab1f76b | iOS build; individual behavior not independently verified |
| ios/Sources/ProcurementView.swift | a103c44be275 | iOS build; individual behavior not independently verified |
| ios/Sources/ProgramsView.swift | 63d0264015db | iOS build; individual behavior not independently verified |
| ios/Sources/ReferralsView.swift | 458d622cdef8 | iOS build; individual behavior not independently verified |
| ios/Sources/ScheduleView.swift | 46a4eefa3a26 | Targeted source review and/or changed behavior verification |
| ios/Sources/ScoredAssessmentsView.swift | d92c04e6b04b | iOS build; individual behavior not independently verified |
| ios/Sources/SearchView.swift | b8b382f6727d | Targeted source review and/or changed behavior verification |
| ios/Sources/SignatureView.swift | 5fd2cb3bada6 | iOS build; individual behavior not independently verified |
| ios/Sources/TrackDetailView.swift | 1142dbb8dfac | iOS build; individual behavior not independently verified |
| ios/Sources/UploadDocumentSheet.swift | 8b2b7e480bd2 | iOS build; individual behavior not independently verified |
| ios/Sources/UtilizationView.swift | fef64f8a4632 | Targeted source review and/or changed behavior verification |
| ios/Sources/VoiceAssistant.swift | f4520fea8943 | iOS build; individual behavior not independently verified |
| public/.htaccess | b6af3d78b35e | Targeted source review and/or changed behavior verification |
| public/access_bookings.php | a24e205b34ed | PHP syntax; broader runtime coverage varies by route |
| public/accommodations.php | 71064f47297d | PHP syntax; broader runtime coverage varies by route |
| public/account.php | 70307d4a401e | PHP syntax; broader runtime coverage varies by route |
| public/ack.php | e78e13df8e7d | PHP syntax; broader runtime coverage varies by route |
| public/agency_report.php | cb4c48b818dd | Targeted source review and/or changed behavior verification |
| public/ai.php | 94f309a686d9 | PHP syntax; broader runtime coverage varies by route |
| public/ai_command.php | 5b495f857468 | PHP syntax; broader runtime coverage varies by route |
| public/ai_speak.php | 7d1ab8a17bb6 | PHP syntax; broader runtime coverage varies by route |
| public/ai_tools.php | fef7d30a8a53 | PHP syntax; broader runtime coverage varies by route |
| public/amendment_request.php | 49242e3f2489 | PHP syntax; broader runtime coverage varies by route |
| public/analytics.php | dae7541c4609 | Targeted source review and/or changed behavior verification |
| public/api.php | 22c9abcbb203 | Targeted source review and/or changed behavior verification |
| public/ask.php | 7f91df83c0f3 | Targeted source review and/or changed behavior verification |
| public/assets/at-ai.js | 19ac2e2405df | Inventoried; no claim of individual source review |
| public/assets/earcons.js | 9ee7e86565f8 | Inventoried; no claim of individual source review |
| public/assets/low-vision.js | e3ba735574f6 | Inventoried; no claim of individual source review |
| public/assets/qrcode.js | a8035e5d1591 | Inventoried; no claim of individual source review |
| public/assets/session-forms.js | 06af7f2c3930 | Inventoried; no claim of individual source review |
| public/assets/session-timeout.js | d334fc5d5bbb | Inventoried; no claim of individual source review |
| public/assets/styles.css | 7da55b272a10 | Inventoried; no claim of individual source review |
| public/assets/voice.js | 3565a8058616 | Inventoried; no claim of individual source review |
| public/at_ai.php | eee28dc93153 | PHP syntax; broader runtime coverage varies by route |
| public/at_report.php | 94dff7be6f28 | PHP syntax; broader runtime coverage varies by route |
| public/at_services.php | 86a13ea6f4bf | PHP syntax; broader runtime coverage varies by route |
| public/audit.php | ccc4808282bb | PHP syntax; broader runtime coverage varies by route |
| public/calfeed.php | 3d35bd723f8c | PHP syntax; broader runtime coverage varies by route |
| public/capacity.php | e01772eeaeb5 | Targeted source review and/or changed behavior verification |
| public/client.php | 7faba49a565b | PHP syntax; broader runtime coverage varies by route |
| public/client_edit.php | d5b65c931bcb | PHP syntax; broader runtime coverage varies by route |
| public/client_export.php | cbc4bd490494 | PHP syntax; broader runtime coverage varies by route |
| public/client_merge.php | 8fba865c47e0 | PHP syntax; broader runtime coverage varies by route |
| public/clients.php | 27f8bd66cf75 | PHP syntax; broader runtime coverage varies by route |
| public/community_referral.php | 345669cb3757 | PHP syntax; broader runtime coverage varies by route |
| public/compliance.php | 30db0978b7b8 | PHP syntax; broader runtime coverage varies by route |
| public/consent.php | f633e1852ca8 | PHP syntax; broader runtime coverage varies by route |
| public/contracts.php | 03722de41de1 | PHP syntax; broader runtime coverage varies by route |
| public/counselors.php | e19df2ddebd8 | PHP syntax; broader runtime coverage varies by route |
| public/curriculum.php | 8207e9263eee | PHP syntax; broader runtime coverage varies by route |
| public/curriculum_ai.php | bfa3a3b2f898 | PHP syntax; broader runtime coverage varies by route |
| public/doc_audit.php | b579dd0a1d1b | PHP syntax; broader runtime coverage varies by route |
| public/download.php | c109247aa28d | PHP syntax; broader runtime coverage varies by route |
| public/email_templates.php | cd2e8bd1118b | PHP syntax; broader runtime coverage varies by route |
| public/equipment.php | 99a1a0365cd9 | PHP syntax; broader runtime coverage varies by route |
| public/equipment_import.php | b8b5e5e33292 | PHP syntax; broader runtime coverage varies by route |
| public/equipment_labels.php | d9f4bae48a5c | PHP syntax; broader runtime coverage varies by route |
| public/eval_report.php | 253f43a45b3c | PHP syntax; broader runtime coverage varies by route |
| public/export.php | 98122005d867 | PHP syntax; broader runtime coverage varies by route |
| public/federation.php | 8fd61060d472 | PHP syntax; broader runtime coverage varies by route |
| public/followups.php | 4e040df252d4 | PHP syntax; broader runtime coverage varies by route |
| public/forgot.php | 754dde02ce15 | PHP syntax; broader runtime coverage varies by route |
| public/funder_report.php | ebf99c8f44cb | Targeted source review and/or changed behavior verification |
| public/help.php | af937b4c752d | PHP syntax; broader runtime coverage varies by route |
| public/homework_edit.php | f451c98c7e0f | PHP syntax; broader runtime coverage varies by route |
| public/inc/access.php | 3e86e15add59 | Targeted source review and/or changed behavior verification |
| public/inc/accessibility.php | 6460fa0afc9c | PHP syntax; broader runtime coverage varies by route |
| public/inc/ai.php | e8b1afa7678c | PHP syntax; broader runtime coverage varies by route |
| public/inc/app_pdf.php | e1f039291563 | PHP syntax; broader runtime coverage varies by route |
| public/inc/at_ai.php | de24c7da200e | PHP syntax; broader runtime coverage varies by route |
| public/inc/at_api.php | e1c4fedaa186 | PHP syntax; broader runtime coverage varies by route |
| public/inc/at_catalog.php | 9c6b8182b3c5 | PHP syntax; broader runtime coverage varies by route |
| public/inc/at_homework.php | a95a79ecb12a | PHP syntax; broader runtime coverage varies by route |
| public/inc/at_portal.php | 2baa5e5ea407 | PHP syntax; broader runtime coverage varies by route |
| public/inc/at_services.php | b94ef9eb3f80 | PHP syntax; broader runtime coverage varies by route |
| public/inc/bootstrap.php | 67fd2a35dce8 | PHP syntax; broader runtime coverage varies by route |
| public/inc/client_export.php | 981e7da53660 | PHP syntax; broader runtime coverage varies by route |
| public/inc/client_merge.php | 9b3ad7deb685 | PHP syntax; broader runtime coverage varies by route |
| public/inc/core.php | a238f3eba4f1 | PHP syntax; broader runtime coverage varies by route |
| public/inc/document.php | 05ec5c45a1c7 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/courier.php | 3eeedf5e86a7 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/courierb.php | 0b84ad97b245 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/courierbi.php | cdad324a23ce | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/courieri.php | 3786fc3787ad | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/helvetica.php | 254c5044d933 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/helveticab.php | 6d5da900f8d3 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/helveticabi.php | 3a47f8aa1c59 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/helveticai.php | 6eb7a38f96c4 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/symbol.php | 34a66b876c18 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/times.php | e3319d094bc7 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/timesb.php | 7c986c85285c | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/timesbi.php | f60eabb1a4b9 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/timesi.php | 888e20cce6e3 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/font/zapfdingbats.php | 81a7ad3df518 | PHP syntax; broader runtime coverage varies by route |
| public/inc/fpdf/fpdf.php | 9450ffb3d8e5 | PHP syntax; broader runtime coverage varies by route |
| public/inc/graph_mail.php | 62ec1749cd0a | PHP syntax; broader runtime coverage varies by route |
| public/inc/i18n.php | 9e96d154fb86 | PHP syntax; broader runtime coverage varies by route |
| public/inc/lang_es.php | e1a87b99b38b | PHP syntax; broader runtime coverage varies by route |
| public/inc/layout.php | 11c121c8f91b | PHP syntax; broader runtime coverage varies by route |
| public/inc/program_access.php | 6e4f7b4791b8 | PHP syntax; broader runtime coverage varies by route |
| public/inc/report_builder.php | 17a71cb75ffa | PHP syntax; broader runtime coverage varies by route |
| public/inc/security.php | bbd527ac24e2 | Targeted source review and/or changed behavior verification |
| public/inc/task_filters.php | 7ef839301098 | PHP syntax; broader runtime coverage varies by route |
| public/inc/tasks.php | 0fedf764b079 | PHP syntax; broader runtime coverage varies by route |
| public/inc/totp.php | f71fb21c8bdf | PHP syntax; broader runtime coverage varies by route |
| public/inc/voice.php | 446203641d5d | PHP syntax; broader runtime coverage varies by route |
| public/inc/webauthn.php | 39d631750e11 | PHP syntax; broader runtime coverage varies by route |
| public/incidents.php | d8627fc92371 | PHP syntax; broader runtime coverage varies by route |
| public/index.php | 41a1c95dabd1 | PHP syntax; broader runtime coverage varies by route |
| public/intake.php | 21a6d335d310 | PHP syntax; broader runtime coverage varies by route |
| public/invoice_pdf.php | 281d38519652 | PHP syntax; broader runtime coverage varies by route |
| public/invoices.php | 4d636f087a70 | PHP syntax; broader runtime coverage varies by route |
| public/kb.php | 80b5d1f19f2d | PHP syntax; broader runtime coverage varies by route |
| public/kb_ask.php | 7f12f4d16f2f | PHP syntax; broader runtime coverage varies by route |
| public/login.php | ca9b5973a40a | PHP syntax; broader runtime coverage varies by route |
| public/logout.php | 908ce89744a0 | PHP syntax; broader runtime coverage varies by route |
| public/messages.php | 37c11186c530 | PHP syntax; broader runtime coverage varies by route |
| public/notifications.php | af8f3316f41b | PHP syntax; broader runtime coverage varies by route |
| public/outcomes.php | a33f249e6dda | Targeted source review and/or changed behavior verification |
| public/passkey.php | ca822082133c | PHP syntax; broader runtime coverage varies by route |
| public/ping.php | c5edbc39dd89 | PHP syntax; broader runtime coverage varies by route |
| public/pipeline.php | b1881d4c71ab | PHP syntax; broader runtime coverage varies by route |
| public/portal.php | b98af4731fe2 | PHP syntax; broader runtime coverage varies by route |
| public/portal_requests.php | 3a90503c9561 | PHP syntax; broader runtime coverage varies by route |
| public/procurement.php | 317449ea42fc | PHP syntax; broader runtime coverage varies by route |
| public/programs.php | d4b71efde8bc | PHP syntax; broader runtime coverage varies by route |
| public/progress_review.php | d1c03cc03ef8 | PHP syntax; broader runtime coverage varies by route |
| public/recommend.php | 66b48fbc5f32 | PHP syntax; broader runtime coverage varies by route |
| public/referral.php | c26c05f62e21 | PHP syntax; broader runtime coverage varies by route |
| public/referrals.php | 0990df2f05ec | PHP syntax; broader runtime coverage varies by route |
| public/remediate.php | a58fb3ac1b4b | PHP syntax; broader runtime coverage varies by route |
| public/report.php | 66ebfa3e02a0 | PHP syntax; broader runtime coverage varies by route |
| public/reset.php | 16c16a0066d8 | PHP syntax; broader runtime coverage varies by route |
| public/risk.php | d59da19cadce | PHP syntax; broader runtime coverage varies by route |
| public/rsa_report.php | 312f2937ba35 | Targeted source review and/or changed behavior verification |
| public/schedule.php | 629784589a0f | PHP syntax; broader runtime coverage varies by route |
| public/score_assessment.php | 4fbe0926ffb0 | PHP syntax; broader runtime coverage varies by route |
| public/search.php | 8382081ed904 | PHP syntax; broader runtime coverage varies by route |
| public/self_referral.php | 9f99c8fd10d3 | PHP syntax; broader runtime coverage varies by route |
| public/sso.php | 33ca68fe80e4 | Targeted source review and/or changed behavior verification |
| public/survey.php | 26dc23791931 | PHP syntax; broader runtime coverage varies by route |
| public/tasks.php | 60398a6f40c1 | PHP syntax; broader runtime coverage varies by route |
| public/templates.php | a41fdf31b87f | PHP syntax; broader runtime coverage varies by route |
| public/track.php | cf23973d59e2 | PHP syntax; broader runtime coverage varies by route |
| public/track_edit.php | b32781c416a3 | PHP syntax; broader runtime coverage varies by route |
| public/translate.php | 624d003bd582 | PHP syntax; broader runtime coverage varies by route |
| public/trash.php | e7bc3ba231e3 | PHP syntax; broader runtime coverage varies by route |
| public/users.php | b896e53ec7c1 | PHP syntax; broader runtime coverage varies by route |
| public/utilization.php | 73c5058b397a | Targeted source review and/or changed behavior verification |
| public/verify.php | c0aa0979cb18 | PHP syntax; broader runtime coverage varies by route |
| public/vrc.php | 55793965517b | PHP syntax; broader runtime coverage varies by route |
| tests/accessibility.php | a5d4e4ff2e27 | PHP syntax; broader runtime coverage varies by route |
| tests/accessibility.test.cjs | 072cf962cc50 | Inventoried; no claim of individual source review |
| tests/accessibility_fixture.php | 7869c3c368cf | PHP syntax; broader runtime coverage varies by route |
| tests/analytics_cases.php | 7d775146d19f | Targeted source review and/or changed behavior verification |
| tests/at-ai.test.cjs | e30a25720489 | Inventoried; no claim of individual source review |
| tests/at_extended_cases.php | d21c0b0954cf | Targeted source review and/or changed behavior verification |
| tests/at_service_cases.php | 77d3707c98ed | PHP syntax; broader runtime coverage varies by route |
| tests/audit_regression.php | 5997cb5bd063 | PHP syntax; broader runtime coverage varies by route |
| tests/calendar_cases.php | 73fc0a34c6c6 | PHP syntax; broader runtime coverage varies by route |
| tests/cron_regression.py | f42647ff1816 | Inventoried; no claim of individual source review |
| tests/form_messages.php | 4e69a4c955a1 | PHP syntax; broader runtime coverage varies by route |
| tests/full_schema.php | 9cf5d840dedb | Targeted source review and/or changed behavior verification |
| tests/installer_gate.py | f0575a540347 | Targeted source review and/or changed behavior verification |
| tests/ios_api_regression.py | 127341ea1c5b | Inventoried; no claim of individual source review |
| tests/ios_models.py | dc59fb92aabf | Inventoried; no claim of individual source review |
| tests/low-vision.test.cjs | 5c39cbf468da | Inventoried; no claim of individual source review |
| tests/public_confirmation_cases.php | 2370ddcbbae8 | PHP syntax; broader runtime coverage varies by route |
| tests/security.php | 242663f7c6c5 | Targeted source review and/or changed behavior verification |
| tests/session-forms.test.cjs | 34c9ce42be63 | Inventoried; no claim of individual source review |
| tests/session-timeout.test.cjs | 1f93cfbc2a32 | Inventoried; no claim of individual source review |
| tests/track_edit_cases.php | f8a2efec5313 | PHP syntax; broader runtime coverage varies by route |
| tests/trash_cases.php | 69d517937a8f | PHP syntax; broader runtime coverage varies by route |
