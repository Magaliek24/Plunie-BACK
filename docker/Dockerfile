FROM php:8.2-apache

# Installer ssmtp pour l'envoi d'emails
RUN apt-get update && apt-get install -y ssmtp && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Configuration ssmtp pour Mailpit
RUN echo "mailhub=plunie_mail:1025\n\
    UseTLS=NO\n\
    FromLineOverride=YES" > /etc/ssmtp/ssmtp.conf

# Configuration PHP pour utiliser ssmtp
RUN echo "sendmail_path = /usr/sbin/ssmtp -t" >> /usr/local/etc/php/conf.d/mail.ini

# Activer mod_rewrite
RUN a2enmod rewrite

CMD ["apache2-foreground"]