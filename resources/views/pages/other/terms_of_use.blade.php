@extends('layouts.login_master')

@section('content')
<main class="page-content">
    <div class="content-wrapper">
        <div class="content">
            <article class="card">
                <header class="card-header">
                    <h1 class="card-title font-weight-bold text-center">TERMS &amp; CONDITIONS</h1>
                    <p class="text-center mb-0">NexaSMS by Mathias</p>
                </header>
                <div class="card-body">
                    <div class="col-md-10 offset-md-1" style="font-size: 16px;">
                        <p>Effective date: {{ now()->format('F j, Y') }}</p>
                        <p>These Terms &amp; Conditions (the “Terms”) govern access to and use of NexaSMS by Mathias (the “Platform”). They apply to schools, administrators, staff, parents or guardians, students, and other people using an account. A separate written agreement may add to or replace parts of these Terms for the school that signed it.</p>

                        <section id="acceptance">
                            <h2 class="h4 font-weight-semibold">1. Acceptance of Terms</h2>
                            <p>By accessing or using the Platform, you agree to these Terms and the <a href="{{ route('privacy_policy', [], false) }}">Privacy Policy</a>. If you use the Platform for a school or another organization, you confirm that you are authorized to accept these Terms on its behalf. Do not use the Platform if you do not agree.</p>
                        </section>

                        <section id="about-nexasms">
                            <h2 class="h4 font-weight-semibold">2. About NexaSMS</h2>
                            <p>NexaSMS by Mathias is a school-management platform. Depending on what is enabled and used, it may provide student, parent or guardian, teacher and staff management; classes, sections and subjects; examinations, marks and results; attendance; academic years and terms; fees, payments and receipts; library, dormitory and timetable functions; reports; user accounts; and permissions. Features may change over time.</p>
                        </section>

                        <section id="platform-use">
                            <h2 class="h4 font-weight-semibold">3. Use of the Platform</h2>
                            <p>You may use the Platform only for lawful school-management and related purposes, in accordance with these Terms, your school’s instructions and permissions, and any applicable agreement. You are responsible for actions taken through your account.</p>
                        </section>

                        <section id="school-responsibilities">
                            <h2 class="h4 font-weight-semibold">4. School Responsibilities</h2>
                            <p>Each school is responsible for the information it enters and its accuracy; managing its users and assigning appropriate permissions; protecting credentials; deciding which people may access student and school information; using the Platform lawfully and following applicable requirements and its own policies; and obtaining any permissions, notices or consents needed for information it provides. The Platform does not guarantee that a school complies with every legal requirement.</p>
                        </section>

                        <section id="accounts">
                            <h2 class="h4 font-weight-semibold">5. User Accounts</h2>
                            <p>Accounts are for the authorized person to whom they are assigned. Users must provide accurate account information when requested, use only accounts they are authorized to use, and promptly tell the appropriate school administrator if an account is no longer needed or its access should change. Schools are responsible for creating, reviewing and disabling their users’ access.</p>
                        </section>

                        <section id="account-security">
                            <h2 class="h4 font-weight-semibold">6. Account Security</h2>
                            <p>Keep passwords confidential, do not share accounts, and take reasonable steps to protect devices used to access the Platform. Do not access information beyond your authorization or attempt to bypass authentication, permissions or security controls. Report suspected unauthorized access promptly to your school administrator and to the contact listed below.</p>
                        </section>

                        <section id="student-information">
                            <h2 class="h4 font-weight-semibold">7. Student and School Information</h2>
                            <p>The Platform may contain information about students, including children and minors, as well as information about schools and their communities. Schools and authorized users are responsible for ensuring that this information is collected, entered, accessed and used appropriately and lawfully, and only by people with an authorized purpose.</p>
                        </section>

                        <section id="records">
                            <h2 class="h4 font-weight-semibold">8. Academic and Administrative Records</h2>
                            <p>Schools and authorized users are responsible for the accuracy, review and appropriate use of academic, attendance, financial and other administrative records they maintain. Review important records before relying on or distributing them. The Platform may assist with recording and reporting information but does not independently verify every entry.</p>
                        </section>

                        <section id="tenant-separation">
                            <h2 class="h4 font-weight-semibold">9. Multi-School/Tenant Separation</h2>
                            <p>NexaSMS supports multiple schools operating as separate school environments. A user may access information for their school or information they are otherwise explicitly authorized to access. Attempting to access another school’s information without authorization is prohibited.</p>
                        </section>

                        <section id="acceptable-use">
                            <h2 class="h4 font-weight-semibold">10. Acceptable Use</h2>
                            <p>Use the Platform responsibly, lawfully and in a way that respects the rights and privacy of students, families, staff and other users. Follow school policies and instructions about records, communications and permitted use.</p>
                        </section>

                        <section id="prohibited-activities">
                            <h2 class="h4 font-weight-semibold">11. Prohibited Activities</h2>
                            <p>You must not gain unauthorized access; bypass authentication, permissions or school separation; interfere with the Platform; introduce malware; attack or disrupt servers or databases; scrape or extract information without authorization; impersonate another person; upload unlawful or malicious material; misuse student information; or use the Platform for an unlawful purpose.</p>
                        </section>

                        <section id="intellectual-property">
                            <h2 class="h4 font-weight-semibold">12. Intellectual Property</h2>
                            <p>The NexaSMS software, Platform branding, original interface, documentation and related materials are protected by applicable intellectual-property laws. Except where the law permits, you must not copy or redistribute the software, make unauthorized copies, reverse engineer it, remove proprietary notices or misuse NexaSMS branding. These Terms do not transfer ownership of school-created data or records.</p>
                        </section>

                        <section id="school-data">
                            <h2 class="h4 font-weight-semibold">13. School Data and User Content</h2>
                            <p>Schools remain responsible for information and records they submit to and manage through the Platform, subject to applicable agreements and law. A school authorizes the handling of its information as reasonably necessary to provide, secure, maintain and support the Platform and as otherwise permitted by that school’s agreement and applicable law. NexaSMS does not claim ownership of school or student records and does not grant itself an unrestricted right to sell or use them.</p>
                        </section>

                        <section id="availability">
                            <h2 class="h4 font-weight-semibold">14. Platform Availability and Maintenance</h2>
                            <p>Availability may be affected by maintenance, hosting or network failures, infrastructure problems, security incidents, service providers or circumstances outside reasonable control. We do not promise uninterrupted availability or a particular uptime level. Where reasonably practical, maintenance or interruptions may be communicated through the Platform or the school.</p>
                        </section>

                        <section id="security">
                            <h2 class="h4 font-weight-semibold">15. Security</h2>
                            <p>Reasonable technical and organizational measures are used to protect information handled by the Platform. No system can be guaranteed to be perfectly secure. Security is a shared responsibility between the Platform and schools and authorized users, including protecting credentials, assigning suitable permissions and reporting concerns.</p>
                        </section>

                        <section id="third-parties">
                            <h2 class="h4 font-weight-semibold">16. Third-Party Services</h2>
                            <p>The Platform may depend on service providers or external services where necessary to operate or support it. Their services may be subject to separate terms and privacy notices. No particular third-party provider is promised by these Terms; applicable provider information may be supplied separately where relevant.</p>
                        </section>

                        <section id="suspension">
                            <h2 class="h4 font-weight-semibold">17. Suspension and Termination</h2>
                            <p>Access may be restricted or suspended where reasonably necessary for security concerns, unauthorized access, abuse, violation of these Terms, unlawful activity, maintenance or operational reasons. A school may also manage or end its users’ access. Where practical and appropriate, the school or affected user may be notified. Any separate agreement may govern the school’s service term and termination.</p>
                        </section>

                        <section id="disclaimers">
                            <h2 class="h4 font-weight-semibold">18. Disclaimers</h2>
                            <p>To the extent permitted by applicable law, the Platform is provided without a guarantee that it will be uninterrupted, error-free, perfectly accurate, permanently available, free from data loss or free from security incidents. Schools should review important academic, financial and administrative records. Nothing in these Terms excludes a guarantee or right that cannot lawfully be excluded.</p>
                        </section>

                        <section id="liability">
                            <h2 class="h4 font-weight-semibold">19. Limitation of Liability</h2>
                            <p>To the extent permitted by applicable law, NexaSMS by Mathias will not be liable for indirect, incidental, special or consequential loss arising from use of or inability to use the Platform, or for loss caused by inaccurate information supplied or maintained by a school or user. These Terms do not exclude or limit liability where doing so is not permitted by law. Any additional limits in a separate written agreement apply only as stated there.</p>
                        </section>

                        <section id="indemnification">
                            <h2 class="h4 font-weight-semibold">20. Indemnification</h2>
                            <p>To the extent permitted by applicable law, a school or user is responsible for claims and costs arising from its unlawful use of the Platform, its violation of these Terms, or information or material it submits without the necessary rights or authorization. This section does not require indemnification for a matter caused by NexaSMS’s own breach or where applicable law does not permit it.</p>
                        </section>

                        <section id="changes">
                            <h2 class="h4 font-weight-semibold">21. Changes to the Terms</h2>
                            <p>These Terms may be updated from time to time. The current version will be posted on this page with its effective date. Continued use after updated Terms take effect constitutes acceptance where permitted by applicable law. A separate agreement may set out how changes are notified to a school.</p>
                        </section>

                        <section id="governing-law">
                            <h2 class="h4 font-weight-semibold">22. Governing Law</h2>
                            <p>These Terms are subject to the applicable law governing the relevant relationship. No country, court or dispute forum is designated here. A formal governing-law and dispute-resolution provision may be specified separately in a written agreement.</p>
                        </section>

                        <section id="contact">
                            <h2 class="h4 font-weight-semibold">23. Contact Information</h2>
                            <p>For questions, concerns, support requests, or matters about these Terms regarding NexaSMS by Mathias, you can contact us using the options below. School or student record requests should generally be directed to the relevant school administrator or authorized contact.</p>
                            <div class="d-flex flex-wrap align-items-center mt-3" role="group" aria-label="Contact NexaSMS by Mathias">
                                <a class="btn btn-success mr-2 mb-2" href="https://wa.me/256706526642" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                                <a class="btn btn-primary mr-2 mb-2" href="https://t.me/+256708949155" target="_blank" rel="noopener noreferrer">Telegram</a>
                                <a class="btn btn-outline-secondary mb-2" href="tel:+256207896805">Call Us</a>
                            </div>
                        </section>

                        <hr>
                        <p>Related document: <a href="{{ route('privacy_policy', [], false) }}">Privacy Policy</a>.</p>
                    </div>
                </div>
            </article>
        </div>
    </div>
</main>
@endsection
