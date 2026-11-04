@extends('layouts.login_master')

@section('content')
<main class="page-content">
    <div class="content-wrapper">
        <div class="content">
            <article class="card">
                <header class="card-header">
                    <h1 class="card-title font-weight-bold text-center">PRIVACY POLICY</h1>
                    <p class="text-center mb-0">NexaSMS by Mathias</p>
                </header>
                <div class="card-body">
                    <div class="col-md-10 offset-md-1" style="font-size: 16px;">
                        <p>Effective date: {{ now()->format('F j, Y') }}</p>

                        <section id="introduction">
                            <h2 class="h4 font-weight-semibold">1. Introduction</h2>
                            <p>This Privacy Policy describes how NexaSMS by Mathias (the “Platform”) may handle information when schools and their authorized users use its school-management functions. The information handled and the responsibilities of the school and Platform may vary according to the school’s arrangement, the features it uses and applicable law. This Policy does not determine the legal role of the Platform or a school for every situation.</p>
                        </section>

                        <section id="information">
                            <h2 class="h4 font-weight-semibold">2. Information We May Process</h2>
                            <p>Depending on the features used by a school, information may include:</p>
                            <ul>
                                <li>student names, identification details, dates of birth, gender and contact information;</li>
                                <li>parent or guardian information, and teacher and staff information;</li>
                                <li>class, section, subject, academic year and term information;</li>
                                <li>academic records, examination records, marks, grades and attendance;</li>
                                <li>fee, payment, receipt, library, dormitory and timetable information;</li>
                                <li>account credentials, roles and permission information; and</li>
                                <li>information used for auditing or security, which may include an IP address, browser or device details, user-agent details and access or security events.</li>
                            </ul>
                            <p>Not every school collects or uses every category.</p>
                        </section>

                        <section id="collection">
                            <h2 class="h4 font-weight-semibold">3. How Information Is Collected</h2>
                            <p>Information may be entered by schools and authorized users, provided by a person when using an account, or generated as part of normal Platform operation. For example, the Platform may record account activity or technical details needed for authentication, security, troubleshooting and auditing. The information collected depends on the functions in use.</p>
                        </section>

                        <section id="use">
                            <h2 class="h4 font-weight-semibold">4. How Information Is Used</h2>
                            <p>Information may be used to provide school-management functionality, including managing students, staff and parents or guardians; recording attendance; managing examinations and results; managing fees and payments; generating school reports; managing accounts and permissions; maintaining Platform security; troubleshooting; and maintaining and improving the Platform.</p>
                        </section>

                        <section id="school-responsibilities">
                            <h2 class="h4 font-weight-semibold">5. School Responsibilities</h2>
                            <p>Schools are responsible for deciding what information they collect and enter, ensuring it is accurate, determining who is authorized to access it, managing user permissions, using student information appropriately, and meeting applicable privacy and data-protection requirements. The school’s legal relationship with the Platform depends on its particular arrangement and applicable law; this Policy does not declare that NexaSMS is automatically the controller or processor for every school.</p>
                        </section>

                        <section id="accounts">
                            <h2 class="h4 font-weight-semibold">6. User Accounts</h2>
                            <p>Account information may include a user’s name, login identifier, password credentials, school association, role and permissions. Credentials are used to authenticate users and protect access. Schools manage their users and should assign access only to people with an appropriate need. Users should keep passwords confidential and report suspected account misuse.</p>
                        </section>

                        <section id="children">
                            <h2 class="h4 font-weight-semibold">7. Student and Children’s Information</h2>
                            <p>NexaSMS may process information about students, including children and minors, because schools use the Platform for educational and administrative purposes. Schools and authorized users are responsible for ensuring that student information is collected, entered, accessed and used lawfully and with appropriate authority. Requests concerning a student’s information should generally be directed to the relevant school administrator or authorized contact.</p>
                        </section>

                        <section id="records">
                            <h2 class="h4 font-weight-semibold">8. Academic and Administrative Information</h2>
                            <p>Schools may use the Platform to keep academic and administrative records such as class assignments, examinations, marks, results, attendance, fee and payment records, library activity, dormitory information and timetables. The school determines which records it maintains and is responsible for their accuracy, appropriate use and authorized access.</p>
                        </section>

                        <section id="separation">
                            <h2 class="h4 font-weight-semibold">9. Multi-School Data Separation</h2>
                            <p>NexaSMS is designed to separate information between schools. A user authorized for one school should not receive access to another school’s information unless that access is explicitly authorized. Schools and users must not attempt to bypass these access boundaries.</p>
                        </section>

                        <section id="sharing">
                            <h2 class="h4 font-weight-semibold">10. Information Sharing and Disclosure</h2>
                            <p>Information may be available to authorized users of the relevant school. Authorized Platform personnel may access information where reasonably necessary for legitimate operational, security or support purposes. Service providers may handle information where necessary to operate or support the Platform. Information may also be disclosed when required by applicable law or valid legal process. Access and disclosure should be limited to the applicable purpose and permissions. NexaSMS does not claim a right to sell student information.</p>
                        </section>

                        <section id="security">
                            <h2 class="h4 font-weight-semibold">11. Data Security</h2>
                            <p>Reasonable technical and organizational measures are used to protect information. No method of storage or transmission can be guaranteed to be absolutely secure. Schools and users also help protect information by safeguarding credentials, assigning appropriate access and promptly reporting suspected unauthorized activity.</p>
                        </section>

                        <section id="retention">
                            <h2 class="h4 font-weight-semibold">12. Data Retention</h2>
                            <p>Information may be retained for as long as reasonably necessary for school operations, applicable legal obligations, legitimate recordkeeping, security, dispute resolution or contractual requirements. Retention may depend on the school’s instructions and arrangement, the type of information and applicable law. No fixed retention period is promised by this Policy.</p>
                        </section>

                        <section id="cookies">
                            <h2 class="h4 font-weight-semibold">13. Cookies and Similar Technologies</h2>
                            <p>Necessary cookies or session technologies may be used for authentication, security and Platform functionality, such as keeping a user signed in while navigating the application. Disabling these technologies may prevent some features from working. This Policy does not claim that the Platform uses advertising trackers.</p>
                        </section>

                        <section id="third-party-services">
                            <h2 class="h4 font-weight-semibold">14. Third-Party Services</h2>
                            <p>The Platform may rely on service providers or external services where necessary to host, operate, secure or support it. Where a provider handles information for the Platform, its access should be limited to the relevant service purpose and applicable arrangements. Provider-specific information may be supplied separately where relevant. This Policy does not identify a particular provider as being used by every school or deployment.</p>
                        </section>

                        <section id="requests">
                            <h2 class="h4 font-weight-semibold">15. User and School Data Requests</h2>
                            <p>Requests about school or student information should generally be directed through the appropriate school administrator or authorized contact, depending on the request. The school can determine the relevant records and verify the requester’s authority. Requests about Platform accounts, security or this Policy may be sent to the contact below. Requests will be handled subject to applicable agreements and law.</p>
                        </section>

                        <section id="changes">
                            <h2 class="h4 font-weight-semibold">16. Changes to the Privacy Policy</h2>
                            <p>This Policy may be updated from time to time. The current version and its effective date will appear on this page. Schools may receive additional notices under their separate arrangements where applicable.</p>
                        </section>

                        <section id="contact">
                            <h2 class="h4 font-weight-semibold">17. Contact Information</h2>
                            <p>For questions, concerns, support requests, or privacy-related matters regarding NexaSMS by Mathias, you can contact us using the options below. For requests about school or student records, contact the relevant school administrator or authorized contact in the first instance.</p>
                            <div class="d-flex flex-wrap align-items-center mt-3" role="group" aria-label="Contact NexaSMS by Mathias">
                                <a class="btn btn-success mr-2 mb-2" href="https://wa.me/256706526642" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                                <a class="btn btn-primary mr-2 mb-2" href="https://t.me/+256708949155" target="_blank" rel="noopener noreferrer">Telegram</a>
                                <a class="btn btn-outline-secondary mb-2" href="tel:+256207896805">Call Us</a>
                            </div>
                        </section>

                        <hr>
                        <p>Related document: <a href="{{ route('terms_of_use', [], false) }}">Terms &amp; Conditions</a>.</p>
                    </div>
                </div>
            </article>
        </div>
    </div>
</main>
@endsection
