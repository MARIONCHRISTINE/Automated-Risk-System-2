**Risk
Register 
Automation 
System**





Introduction
At Airtel Kenya, managing risk is important in keeping operations smooth and secure. At the moment the risk management process is done manually using excel spreadsheet where the risk is entered as assessed and tracked by hand, which works to a point but has its down sides. This existing method is time consuming prone o human error and hard to scale. It lacks features like real time recording of risk incidents and tracking of the risk events, the automated risk scoring and proper access control. As more risks are recorded, it gets harder to manage everything efficiently.
Objectives 
•	The development of an application to be a repository of risk events reported by all Airtel Money & other staff supporting Airtel Money.
•	The development of a risk incident reporting interphase to facilitate this reporting.
•	The development of a risk rating engine to facilitate risk assessment.
•	The development of a dashboard to allow for reporting of the evolution of the risks.
•	Incorporate Airtel’s current manual risk register.

Requirements 
1.	Functional requirements 
•	Airtel Money staff & other GSM log in to the Risk incident platform and raise risk incident reports on a continuous basis.
•	Airtel Money Risk & Compliance team members should be able to log into the system and:
Define new risk parameters including risk categories, risk incidents
Provide comments over risk incidents reported
Extract reports from the system on the risk environment of the organisation
•	Risk Owners should be able to log into the system and:
Provide comments on the risks they manage
Extract reports on the risks they manage
•	The system should have interactive dashboards indicating the evolution and state of risks at a given point.
•	The system should have a Bulk Upload feature for easier migration of past events- Strictly CSV/Excel

2.	Non-functional

•	Should be secure and not vulnerable to any attacks
•	Scalable for more users or modules
•	Responsive design
•	Reliable uptime and standalone serever
Development Approach
•	Agile development in sprints
•	GitHub for version control
•	Modules broken down as:
I.	Authentication and user roles
II.	Risk incident entry
III.	Risk scoring(Python microservice)
IV.	Dashboard and reporting
V.	Stand alone deployment







System design
A.	Front-end
HTML, CSS, Javascript
Roles: Staff, Risk Owners, Compliance Team

B.	Back-end (Application layer)
PHP handles: 
•	Authentication and sessions
•	Risk submission logic
•	Dashboard data
•	Communication with risk engine

C.	Python microservice
•	Flask API for risk score calculator
•	Takes risk factors, returns rating and numeric score for PHP

D.	Database layer
MySQL Stores: 
•	Users
•	Risk Incidents
•	Risk Categories, scores, logs

E.	Deployment
Standard hosting, local server preferred for now
Future proof design to allow later integration via subdomain or portal









	

	



			

	

	


	




	



	
Required Integrated Development Environment(IDE’s Required)
1.	Visual studio code(Vs code)
For developing HTML, CSS and Javascript components
For writing and debugging PHP code
With Python Extension for developing and testing the Flask-based risk scoring service(Latest Version 3.10+ for flask microservice)
2.	XAMPP
To run a local server for PHP and MySQL
3.	phpMyAdmin 
For managing the My SQL database
4.	Git and GitHub Desktop 
For source code versioning and collaboration




MySQL said: Documentation

#1452 - Cannot add or update a child row: a foreign key constraint fails (`airtel_risk_system`.`#sql-41a4_6d`, CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`))
