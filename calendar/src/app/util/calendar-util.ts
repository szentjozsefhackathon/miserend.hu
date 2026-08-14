import {CalendarOptions} from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import rrulePlugin from '@fullcalendar/rrule';
import huLocale from '@fullcalendar/core/locales/hu';
import {DialogEvent} from '../model/dialog-event';
import {LanguageCode} from '../enum/language-code';
import {Renum} from '../enum/recurrence';
import {Church} from '../model/church';
import {DateTimeUtil} from './date-time-util';
import {Day} from '../enum/day';
import {MassUtil} from './mass-util';
import {TranslateService} from '@ngx-translate/core';

export class CalendarUtil {

  public static getSimpleCalendarOptions(timeZone: string): CalendarOptions {
    // Prefer timeGridWeek when the editor is active (URL contains 'editschedule') so edit flows show week view
    let initialView = 'listWeek';
    if (typeof window !== 'undefined' && window.location && window.location.pathname && window.location.pathname.indexOf('editschedule') !== -1) {
      initialView = 'timeGridWeek';
    }

    return {
      plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, rrulePlugin],
      initialView: initialView,
      // Use 30 minute slots for week/time grids (was 20 minutes)
      slotDuration: '00:30',
      allDaySlot: false, 
      // Hide the hour labels in the left time axis (prevent stacked hour strings)
      // slotLabelContent can return an object with html to override rendering
      slotLabelContent: function() { return { html: '' }; },
      timeZone: timeZone,
      locale: huLocale,
      firstDay: 7,
      height: '600px',
      editable: false,
      dayMaxEvents: true,

      validRange: function(nowDate) {
        const currentYear = nowDate.getFullYear();
        return {
          start: new Date(currentYear - 1, 0, 1),
          end: new Date(currentYear + 2, 0, 1)
        };
      }
    }
  }

  public static getSimpleCalendarOptionsWithoutHeader(timeZone: string): CalendarOptions {
    const simpleCalendarOptions = this.getSimpleCalendarOptions(timeZone);
    // ensure the FullCalendar built-in toolbar is disabled so the custom Angular header remains the only visible one
    simpleCalendarOptions.headerToolbar = false;
    return simpleCalendarOptions;
  }

  public static generateDialogEvent(church: Church, translate: TranslateService, date?: Date): DialogEvent {
    const titles = MassUtil.getTitles(church.rite);
    return {
      period: null,
      rite: church.rite,
      types: [],
      title: (titles && titles.length > 0) ? translate.instant(titles.at(0)!) : "",
      start: date ? date : new Date(),
      duration: {hours: 1},
      language: [LanguageCode.HU], // #334: több nyelvű mise is lehet
      renum: Renum.NONE,
      selectedDays: date ? [DateTimeUtil.getShortEnDay(date)] : [Day.SU],
      comment: "",
      editOne: false
    };
  }

}
