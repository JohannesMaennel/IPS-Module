PIN-Belegung:

ESP32-C3 SuperMini
==================

GPIO5  ---> SDA OLED
         |
         +--> SDA ADS1115

GPIO6  ---> SCL OLED
         |
         +--> SCL ADS1115

3V3    ---> VCC OLED
         |
         +--> VDD ADS1115

GND    ---> GND OLED
         |
         +--> GND ADS1115

A0 ADS1115 ---> Drucksensor Signal

Schaltbild: 

          ESP32-C3 SuperMini

               3V3
                |
        +-------+-------+
        |               |
      OLED           ADS1115
      VCC             VDD

               GND
                |
        +-------+-------+
        |               |
      OLED           ADS1115
      GND             GND


GPIO5 (SDA)
    |
    +----- OLED SDA
    |
    +----- ADS1115 SDA

GPIO6 (SCL)
    |
    +----- OLED SCL
    |
    +----- ADS1115 SCL

    