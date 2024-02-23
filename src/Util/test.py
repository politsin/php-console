import wiringpi
import time
import os
import sys
from wiringpi import GPIO

#NUM = 17    #26pin
#NUM = 18   #26pin
#NUM = 20   #for Orange Pi Zero 2
NUM = 10   #for Orange Pi 4
#NUM = 28   #40pin

wiringpi.wiringPiSetup()

wiringpi.pinMode(NUM, GPIO.INPUT) ;

while True:
    try:
        if wiringpi.digitalRead(NUM):
           # wiringpi.digitalWrite(5, GPIO.LOW)

            os.system("/home/orangepi/scripts/get_dial.sh")
            time.sleep(0.5)
        else:
          #  os.system("/home/orangepi/scripts/get_dial.sh")
         #   wiringpi.digitalWrite(5, GPIO.LOW)
            time.sleep(0.5)
    except KeyboardInterrupt:
        print("\nexit")
        sys.e
