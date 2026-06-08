# AWS Deployment Checklist
**Deployment Date:** ________________  
**Deployed By:** ________________

---

## Phase 1: AWS Account Setup
- [ ] AWS account created with factinter@mit.edu
- [ ] Billing alerts enabled ($150 threshold)
- [ ] IAM user `facthub-dev` created
- [ ] Login credentials saved securely

---

## Phase 2: VPC & Networking
- [ ] VPC created: `facthub-vpc` (10.0.0.0/16)
- [ ] Public subnets created:
  - [ ] `facthub-public-1a` (10.0.1.0/24, us-east-1a)
  - [ ] `facthub-public-1b` (10.0.2.0/24, us-east-1b)
- [ ] Private subnets created:
  - [ ] `facthub-private-1a` (10.0.10.0/24, us-east-1a)
  - [ ] `facthub-private-1b` (10.0.11.0/24, us-east-1b)
- [ ] Internet Gateway created and attached: `facthub-igw`
- [ ] Route tables configured
- [ ] Security groups created:
  - [ ] `facthub-alb-sg` (HTTP/HTTPS from anywhere)
  - [ ] `facthub-ec2-sg` (Traffic from ALB, SSH from your IP)
  - [ ] `facthub-rds-sg` (MySQL 3306 from EC2)

---

## Phase 3: RDS Database
- [ ] DB Subnet Group created: `facthub-db-subnet`
- [ ] RDS instance created: `facthub-db`
  - [ ] Engine: MySQL 8.0
  - [ ] Instance type: db.t3.small
  - [ ] Multi-AZ: ✅ Enabled
  - [ ] Backup retention: 30 days
  - [ ] Encryption: ✅ Enabled
  - [ ] Enhanced monitoring: ✅ Enabled
- [ ] Database is available (status: "Available")
- [ ] RDS endpoint: ________________
- [ ] Master password saved in secure location
- [ ] Production database restored: `mysql -h [RDS_ENDPOINT] -u admin -p fact_hub2 < fact_hub2.sql`
- [ ] Database connection tested

---

## Phase 4: EC2 Application Servers
- [ ] EC2 Key pair created: `facthub-key`
- [ ] Key pair downloaded and secured
- [ ] Instance 1 launched: `facthub-app-1`
  - [ ] AMI: Ubuntu 22.04 LTS
  - [ ] Instance type: t3.medium
  - [ ] Subnet: `facthub-public-1a`
  - [ ] Public IP assigned
  - [ ] User data script executed
  - [ ] Status: Running ✅
- [ ] Instance 2 launched: `facthub-app-2`
  - [ ] Subnet: `facthub-public-1b`
  - [ ] Status: Running ✅
- [ ] SSH access tested to both instances
- [ ] Application code deployed from GitHub
- [ ] config/database.php configured with RDS endpoint
- [ ] Nginx running: `sudo systemctl status nginx`
- [ ] PHP-FPM running: `sudo systemctl status php8.1-fpm`
- [ ] Application accessible via instance IP

---

## Phase 5: Application Load Balancer
- [ ] Target group created: `facthub-tg`
  - [ ] Health check path: `/index.php?page=researchers`
  - [ ] Targets: Both EC2 instances registered
- [ ] ALB created: `facthub-alb`
  - [ ] VPC: `facthub-vpc`
  - [ ] Subnets: Both public subnets
  - [ ] Security group: `facthub-alb-sg`
  - [ ] Status: Active ✅
- [ ] HTTP listener configured (port 80)
- [ ] ALB DNS name: ________________
- [ ] ALB accessible via HTTP

---

## Phase 6: SSL/TLS Certificates
- [ ] ACM certificate requested for `factalliancehub.mit.edu`
- [ ] Validation method: DNS ✅
- [ ] Certificate status: Issued ✅
- [ ] HTTPS listener added to ALB (port 443)
- [ ] Certificate attached to ALB listener
- [ ] HTTP → HTTPS redirect configured

---

## Phase 7: DNS Configuration
- [ ] MIT contacted (Cam Fox / mit-brand@mit.edu)
- [ ] DNS CNAME requested: `factalliancehub.mit.edu → [ALB_DNS]`
- [ ] DNS propagation confirmed: `nslookup factalliancehub.mit.edu`
- [ ] HTTPS certificate validation complete
- [ ] factalliancehub.mit.edu accessible via browser
- [ ] SSL certificate valid (green lock in browser)

---

## Phase 8: Monitoring & Backups
- [ ] CloudWatch dashboard created: `facthub-monitoring`
- [ ] Alarms configured:
  - [ ] EC2 CPU > 70%
  - [ ] RDS CPU > 75%
  - [ ] RDS free storage < 10 GB
  - [ ] ALB response time > 5s
- [ ] SNS notifications enabled
- [ ] RDS automated backups: 30-day retention ✅
- [ ] EC2 AMI backup created: `facthub-app-backup-2026-06-08`

---

## Phase 9: Deployment Pipeline
- [ ] deploy.sh script created and tested
- [ ] Git pull tested on EC2 instances
- [ ] Service restart tested (no downtime)
- [ ] Deployment to both instances tested sequentially

---

## Phase 10: Testing & Cutover

### Pre-Launch Tests
- [ ] HTTPS certificate valid: `curl -I https://factalliancehub.mit.edu`
- [ ] Homepage loads: `curl https://factalliancehub.mit.edu/`
- [ ] Researchers page accessible
- [ ] Funding calls page accessible
- [ ] Login page loads
- [ ] Admin panel accessible (with credentials)
- [ ] Database queries working (no errors in logs)
- [ ] Email sending functional (test registration)
- [ ] File permissions correct (755 for dirs, 644 for files)

### Load Testing (Optional)
- [ ] Apache Bench installed on local machine
- [ ] 1000 requests, 200 concurrent: `ab -n 1000 -c 200 https://factalliancehub.mit.edu/`
- [ ] Response time: < 2 seconds ✅
- [ ] Failed requests: 0 ✅
- [ ] CloudWatch shows normal CPU/memory during test

### Cutover
- [ ] Team notified of migration
- [ ] Old server (54.221.189.212) backed up
- [ ] MIT verifies DNS CNAME is live
- [ ] All users redirected to new URL
- [ ] Monitoring active (first 24 hours)
- [ ] Rollback plan documented (if needed)

---

## Phase 11: Post-Launch (Week 1)
- [ ] Monitor CloudWatch dashboard daily
- [ ] Check error logs: `/var/log/nginx/facthub_error.log`
- [ ] Collect user feedback
- [ ] Performance baseline established
- [ ] Auto-scaling policies configured (if needed)
- [ ] Documentation updated with new URLs

---

## Infrastructure Summary

**AWS Account:** factinter@mit.edu  
**Region:** us-east-1  
**VPC:** facthub-vpc (10.0.0.0/16)  

**Database:**
- RDS Endpoint: _______________________
- Database: fact_hub2
- Username: admin
- Backup retention: 30 days

**Application Servers:**
- EC2 #1: facthub-app-1 (_______________________)
- EC2 #2: facthub-app-2 (_______________________)
- Type: t3.medium x2
- OS: Ubuntu 22.04 LTS

**Load Balancer:**
- DNS: _______________________
- Certificate: ✅ factalliancehub.mit.edu
- HTTPS: ✅ Enabled with redirect

**Domain:**
- factalliancehub.mit.edu → [ALB DNS]
- SSL Certificate: ✅ Valid

---

## Emergency Contacts
- **AWS Support:** [Support Console](https://console.aws.amazon.com/support)
- **MIT DNS (Cam Fox):** cam.fox@mit.edu (or ServiceNow INC1823118)
- **Infrastructure Lead:** [Your Name] - [Your Email]

---

## Notes
_Use this section for any observations, issues encountered, or future improvements:_

```
[Notes here]
```

---

✅ **Deployment Complete!** AWS infrastructure is now production-ready.

**Next Steps:**
1. Monitor for 7 days
2. Collect user feedback
3. Plan optimization if needed
4. Schedule quarterly disaster recovery drills
